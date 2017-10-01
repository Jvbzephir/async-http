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

use KoolKode\Async\Context;
use KoolKode\Async\Disposable;
use KoolKode\Async\Promise;
use KoolKode\Async\Concurrent\Executor;
use KoolKode\Async\Concurrent\Synchronized;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableStream;

/**
 * WebSocket connection based on a socket and the protocol defined in RFC 6455.
 *
 * @link https://tools.ietf.org/html/rfc6455
 *
 * @author Martin Schröder
 */
class Connection implements Disposable
{
    /**
     * WebSocket GUID needed during handshake.
     *
     * @var string
     */
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    /**
     * Socket stream being used to transmit frames.
     *
     * @var DuplexStream
     */
    protected $stream;
    
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
    
    protected $executor;
    
    protected $reader;
    
    protected $sync;
    
    protected $cancel;
    
    protected $background;
    
    public function __construct(Context $context, bool $client = true, DuplexStream $stream, string $protocol = '')
    {
        $this->client = $client;
        $this->stream = $stream;
        $this->protocol = $protocol;
        
        $this->reader = new MessageReader($this->maxTextMessageSize);
        $this->sync = new Synchronized();
        
        $context = $context->background();
        $context = $context->cancellable($this->cancel = $context->cancellationHandler());
        
        $this->background = $context;
        $this->executor = new Executor();
        
        Context::rethrow($context->task($this->processFrames($context)));
    }
    
    /**
     * {@inheritdoc}
     */
    public function close(?\Throwable $e = null): void
    {
        $this->reader->close($e);
        $this->cancel->cancel('Connection closed', $e);
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
     * Send a text message to the remote endpoint.
     *
     * @param string $text The text to be sent (must be UTF-8 encoded).
     * @param int $priority Message priority.
     * @return int Number of transmitted bytes.
     *
     * @throws \InvalidArgumentException When the given message is not UTF-8 encoded.
     */
    public function sendText(Context $context, string $text, int $priority = 0): Promise
    {
        if (!\preg_match('//u', $text)) {
            return new \InvalidArgumentException('Message is not UTF-8 encoded');
        }
        
        return $this->executor->submit($context, function (Context $context) use ($text) {
            $type = Frame::TEXT;
            $len = 0;
            
            $chunks = \str_split($text, 8192);
            
            for ($size = \count($chunks) - 1, $i = 0; $i < $size; $i++) {
                $len += yield $this->writeFrame($context, new Frame($type, $chunks[$i], false));
                
                $type = Frame::CONTINUATION;
            }
            
            return $len + yield $this->writeFrame($context, new Frame($type, $chunks[$i]));
        }, $priority);
    }
    
    /**
     * Send contents of the given stream as binary message.
     *
     * @param ReadableStream $stream Source of data to be sent (will be closed after all bytes have been consumed).
     * @param int $priority Message priority.
     * @return int Number of transmitted bytes.
     */
    public function sendBinary(Context $context, ReadableStream $stream, int $priority = 0): Promise
    {
        return $this->executor->submit(function (Context $context) use ($stream) {
            $type = Frame::BINARY;
            $len = 0;
            
            try {
                $chunk = yield $stream->readBuffer($context, 8192);
                
                while (null !== ($next = yield $stream->readBuffer($context, 8192))) {
                    $len += yield $this->writeFrame($context, new Frame($type, $chunk, false));
                    
                    $chunk = $next;
                    $type = Frame::CONTINUATION;
                }
                
                if ($chunk !== null) {
                    $len += yield $this->writeFrame($context, new Frame($type, $chunk));
                }
            } finally {
                $stream->close();
            }
            
            return $len;
        }, $priority);
    }
    
    protected function writeFrame(Context $context, Frame $frame): Promise
    {
        return $this->stream->write($context, $frame->encode($this->client ? \random_bytes(4) : null));
    }
    
    public function receive(Context $context): Promise
    {
        return $this->sync->get($context);
    }
    
    /**
     * Coroutine that handles incoming WebSocket frames.
     */
    protected function processFrames(Context $context): \Generator
    {
        while (true) {
            $frame = yield from $this->readNextFrame($context);
            $message = null;
            
            switch ($frame->opcode) {
                case Frame::TEXT:
                    $message = $this->reader->appendTextFrame($frame);
                    break;
                case Frame::BINARY:
                    $message = $this->reader->appendBinaryFrame($frame);
                    break;
                case Frame::CONTINUATION:
                    $message = yield from $this->reader->appendContinuationFrame($context, $frame);
                    break;
                case Frame::PING:
                    $this->executor->submit($context, $this->writeFrame($context, new Frame(Frame::PONG, $frame->data), 1000));
                    break;
                case Frame::PONG:
                    // TODO: Re-implement pings...
                    break;
                case Frame::CONNECTION_CLOSE:
                    throw new ConnectionException('Remote peer closes connection', Frame::CONNECTION_CLOSE);
            }
            
            if ($message !== null) {
                yield $this->sync->set($context, $message);
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
    protected function readNextFrame(Context $context): \Generator
    {
        list ($byte1, $byte2) = \array_map('ord', \str_split(yield $this->stream->readBuffer($context, 2), 1));
        
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
            $len = \unpack('n', yield $this->stream->readBuffer($context, 2))[1];
        } elseif ($len === 0x7F) {
            $lp = \unpack('N2', yield $this->stream->readBuffer($context, 8));
            
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
            $data = yield $this->stream->readBuffer($context, $len);
        } else {
            $mask = yield $this->stream->readBuffer($context, 4);
            $data = (yield $this->stream->readBuffer($context, $len)) ^ \str_pad($mask, $len, $keySTR_PAD_RIGHT);
        }
        
        return new Frame($byte1 & Frame::OPCODE, $data, ($byte1 & Frame::FINISHED) ? true : false, $byte1 & Frame::RESERVED);
    }
}
