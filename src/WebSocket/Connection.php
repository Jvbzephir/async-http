<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\WebSocket;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Concurrent\Executor;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Deferred;
use KoolKode\Async\Log\LoggerProxy;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Util\Channel;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use KoolKode\Async\Http\Http;

/**
 * WebSocket connection based on a socket and the protocol defined in RFC 6455.
 * 
 * @link https://tools.ietf.org/html/rfc6455
 * 
 * @author Martin Schröder
 */
class Connection implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    /**
     * Socket stream being used to transmit frames.
     * 
     * @var SocketStream
     */
    protected $socket;
    
    /**
     * Client mode flag.
     * 
     * @var bool
     */
    protected $client;
    
    /**
     * Negotiated application protocol.
     * 
     * @var string
     */
    protected $protocol;
    
    /**
     * Inbound WebSocket frame handler.
     * 
     * @var Coroutine
     */
    protected $processor;
    
    /**
     * Buffer for received WebSocket messages.
     * 
     * @var Channel
     */
    protected $messages;
    
    /**
     * Executor that syncs frames being written to the socket.
     * 
     * @var MessageWriter
     */
    protected $writer;
    
    /**
     * Message buffer that is being used to reassemble fragmented messages.
     * 
     * @var TextMessageReader|BinaryMessageReader
     */
    protected $buffer;
    
    /**
     * Extension that provides deflate compression of messages.
     * 
     * @var PerMessageDeflate
     */
    protected $deflate;
    
    /**
     * Maximum frame size (in bytes).
     * 
     * @var int
     */
    protected $maxFrameSize = 0xFFFF;
    
    /**
     * Maximum text message size (in bytes).
     * 
     * @var int
     */
    protected $maxTextMessageSize = 0x80000;
    
    /**
     * Holds references to pings that are waiting for a pong frame.
     * 
     * Each ping transmits a payload of random bytes that is used as key in this array.
     * 
     * @var array
     */
    protected $pings = [];
    
    /**
     * Create a new WebSocket connection.
     * 
     * @param SocketStream $socket Underlying socket transport.
     * @param bool $client Use client mode?
     * @param string $protocol Negotiated application protocol.
     */
    public function __construct(SocketStream $socket, bool $client = true, string $protocol = '')
    {
        $this->socket = $socket;
        $this->client = $client;
        $this->protocol = $protocol;
        
        $this->logger = new LoggerProxy(static::class, Http::LOG_CHANNEL);
        
        $this->writer = new MessageWriter($socket, $client);
        $this->messages = new Channel(4);
        $this->processor = new Coroutine($this->processIncomingFrames(), true);
    }
    
    /**
     * Get the negotiated application protocol.
     * 
     * Returns an empty string if no protocol has been negotiated.
     * 
     * @return string
     */
    public function getProtocol(): string
    {
        return $this->protocol;
    }
    
    /**
     * check if permessage-deflate extension is enabled.
     * 
     * @return bool
     */
    public function isCompressionEnabled(): bool
    {
        return $this->deflate !== null;
    }
    
    /**
     * Enable permessage-deflate WebSocket protocol extension.
     * 
     * @param PerMessageDeflate $deflate
     */
    public function enablePerMessageDeflate(PerMessageDeflate $deflate)
    {
        $this->deflate = $deflate;
        $this->writer = new CompressedMessageWriter($this->socket, $this->client, $deflate);
        
        $this->logger->debug('Enabled extension: {extension}', [
            'extension' => $deflate->getExtensionHeader()
        ]);
    }

    /**
     * Shut down connection.
     * 
     * @return Awaitable
     */
    public function shutdown()
    {
        if ($this->processor) {
            try {
                $this->processor->cancel('WebSocket connection shutdown');
            } finally {
                $this->processor = null;
            }
        }
    }
    
    /**
     * Ping the remote endpoint.
     * 
     * @return bool Returns true after pong frame has been received.
     */
    public function ping(): Awaitable
    {
        $payload = \random_bytes(8);
        
        $defer = new Deferred(function () use ($payload) {
            unset($this->pings[$payload]);
        });
        
        $this->writer->writeFrame(new Frame(Frame::PING, $payload))->when(function (\Throwable $e = null) use ($defer, $payload) {
            if ($e) {
                $defer->fail($e);
            } else {
                $this->pings[$payload] = $defer;
            }
        });
        
        return $defer;
    }
    
    /**
     * Await and consume the next message received by the WebSocket.
     * 
     * Text messages are received as strings, binaray messages are instances ReadableStream.
     * 
     * @return string|ReadableStream
     */
    public function receive(): Awaitable
    {
        return $this->messages->receive();
    }

    /**
     * Send a text message to the remote endpoint.
     * 
     * @param string $text The text to be sent (must be UTF-8 encoded).
     * @param int $priority Message priority.
     * @return int Number of transmitted bytes.
     * 
     * @throws \InvalidArgumentException When the given message is not UTF-8 encoded.
     */
    public function sendText(string $text, int $priority = 0): Awaitable
    {
        return $this->writer->sendText($text, $priority);
    }

    /**
     * Send contents of the given stream as binary message.
     * 
     * @param ReadableStream $stream Source of data to be sent (will be closed after all bytes have been consumed).
     * @param int $priority Message priority.
     * @return int Number of transmitted bytes.
     */
    public function sendBinary(ReadableStream $stream, int $priority = 0): Awaitable
    {
        return $this->writer->sendBinary($stream, $priority);
    }

    /**
     * Coroutine that handles incoming WebSocket frames.
     */
    protected function processIncomingFrames(): \Generator
    {
        $e = null;
        
        try {
            while (true) {
                $frame = yield from $this->readNextFrame();
                
                if ($frame->isControlFrame()) {
                    if (!yield from $this->handleControlFrame($frame)) {
                        break;
                    }
                } else {
                    switch ($frame->opcode) {
                        case Frame::TEXT:
                            yield from $this->handleTextFrame($frame);
                            break;
                        case Frame::BINARY:
                            yield from $this->handleBinaryFrame($frame);
                            break;
                        case Frame::CONTINUATION:
                            yield from $this->handleContinuationFrame($frame);
                            break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->messages->close($e);
        } finally {
            $this->messages->close();
            
            try {
                foreach ($this->pings as $defer) {
                    $defer->fail($e ?? new \RuntimeException('WebSocket connection closed'));
                }
            } finally {
                $this->pings = [];
            }
            try {
                if ($this->socket->isAlive()) {
                    $reason = ($e === null || $e->getCode() === 0) ? Frame::NORMAL_CLOSURE : $e->getCode();
                    
                    yield $this->writer->sendFrame(new Frame(Frame::CONNECTION_CLOSE, \pack('n', $reason)));
                }
            } finally {
                $this->logger->debug('WebSocket connection to {peer} closed', [
                    'peer' => $this->socket->getRemoteAddress()
                ]);
                
                $this->socket->close();
            }
        }
    }

    /**
     * Handle a WebSocket control frame.
     * 
     * Sending pong frames might happen at anytime between sending continuation frames.
     */
    protected function handleControlFrame(Frame $frame): \Generator
    {
        switch ($frame->opcode) {
            case Frame::CONNECTION_CLOSE:
                return false;
            case Frame::PING:
                yield $this->writer->writeFrame(new Frame(Frame::PONG, $frame->data), 1000);
                break;
            case Frame::PONG:
                if (isset($this->pings[$frame->data])) {
                    try {
                        $this->pings[$frame->data]->resolve(true);
                    } finally {
                        unset($this->pings[$frame->data]);
                    }
                }
                break;
        }
        
        return true;
    }

    /**
     * Handle / buffer a text frame.
     */
    protected function handleTextFrame(Frame $frame): \Generator
    {
        if ($this->buffer !== null) {
            throw new ConnectionException('Cannot receive new message while reading continuation frames', Frame::PROTOCOL_ERROR);
        }
        
        $this->buffer = new TextMessageReader($this->messages, $this->maxTextMessageSize, $this->deflate);
        
        if (yield from $this->buffer->appendTextFrame($frame)) {
            $this->buffer = null;
        }
    }

    /**
     * Handle / buffer a binary frame.
     */
    protected function handleBinaryFrame(Frame $frame): \Generator
    {
        if ($this->buffer !== null) {
            throw new ConnectionException('Cannot receive new message while reading continuation frames', Frame::PROTOCOL_ERROR);
        }
        
        $this->buffer = new BinaryMessageReader($this->messages, $this->deflate);
        
        if (yield from $this->buffer->appendBinaryFrame($frame)) {
            $this->buffer = null;
        }
    }

    /**
     * Handle / buffer continuation frames of a fragmented message.
     */
    protected function handleContinuationFrame(Frame $frame): \Generator
    {
        if ($this->buffer === null) {
            throw new ConnectionException('Continuation frame received outside of a message', Frame::PROTOCOL_ERROR);
        }
        
        try {
            if (yield from $this->buffer->appendContinuationFrame($frame)) {
                $this->buffer = null;
            }
        } catch (\Throwable $e) {
            try {
                $this->buffer->dispose($e);
            } finally {
                $this->buffer = null;
            }
        }
    }

    /**
     * Reads the next WebSocket frame from the socket.
     * 
     * This method will unmask frames as needed and asserts frame size constraints.
     * 
     * @return Frame
     */
    protected function readNextFrame(): \Generator
    {
        list ($byte1, $byte2) = \array_map('ord', \str_split(yield $this->socket->readBuffer(2, true), 1));
        
        $masked = ($byte2 & Frame::MASKED) ? true : false;
        
        if ($this->client && $masked) {
            throw new ConnectionException('Received masked frame from server', Frame::PROTOCOL_ERROR);
        }
        
        if (!$this->client && !$masked) {
            throw new ConnectionException('Received unmasked frame from client', Frame::PROTOCOL_ERROR);
        }
        
        // Parse extended length fields:
        $len = $byte2 & Frame::LENGTH;
        
        if ($len === 0x7E) {
            $len = \unpack('n', yield $this->socket->readBuffer(2, true))[1];
        } elseif ($len === 0x7F) {
            $lp = \unpack('N2', yield $this->socket->readBuffer(8, true));
            
            // 32 bit int check:
            if (\PHP_INT_MAX === 0x7FFFFFFF) {
                if ($lp[1] !== 0 || $lp[2] < 0) {
                    throw new ConnectionException('Max payload size exceeded', Frame::MESSAGE_TOO_BIG);
                }
                
                $len = $lp[2];
            } else {
                $len = $lp[1] << 32 | $lp[2];
                
                if ($len < 0) {
                    throw new ConnectionException('Cannot use most significant bit in 64 bit length field', Frame::MESSAGE_TOO_BIG);
                }
            }
        }
        
        if ($len < 0) {
            throw new ConnectionException('Payload length must not be negative', Frame::MESSAGE_TOO_BIG);
        }
        
        if ($len > $this->maxFrameSize) {
            throw new ConnectionException(\sprintf('Maximum frame size of %u bytes exceeded', $this->maxFrameSize), Frame::MESSAGE_TOO_BIG);
        }
        
        // Read and unmask frame data.
        if ($this->client) {
            $data = yield $this->socket->readBuffer($len, true);
        } else {
            $key = yield $this->socket->readBuffer(4, true);
            $data = (yield $this->socket->readBuffer($len, true)) ^ \str_pad($key, $len, $key, \STR_PAD_RIGHT);
        }
        
        return new Frame($byte1 & Frame::OPCODE, $data, ($byte1 & Frame::FINISHED) ? true : false, $byte1 & Frame::RESERVED);
    }
}
