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
use KoolKode\Async\Placeholder;
use KoolKode\Async\Promise;
use KoolKode\Async\Channel\Channel;
use KoolKode\Async\Channel\InputChannel;
use KoolKode\Async\Concurrent\Executor;
use KoolKode\Async\Stream\ReadableStream;

/**
 * WebSocket connection based on a socket and the protocol defined in RFC 6455.
 *
 * @link https://tools.ietf.org/html/rfc6455
 *
 * @author Martin Schröder
 */
class Connection implements InputChannel
{
    /**
     * WebSocket GUID needed during handshake.
     */
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    /**
     * Socket stream being used to transmit frames.
     *
     * @var FramedStream
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
    
    /**
     * Executor being used to synchronize transmitted messages.
     * 
     * @var Executor
     */
    protected $executor;
    
    /**
     * Reader being used to read incoming messages.
     * 
     * @var MessageReader
     */
    protected $reader;
    
    /**
     * Per-message deflate extension.
     * 
     * @var PerMessageDeflate
     */
    protected $deflate;
    
    /**
     * Zlib compression context.
     * 
     * @var resource
     */
    protected $compression;
    
    /**
     * Zlib flush mode of the final frame of a message.
     * 
     * @var int
     */
    protected $flushMode;

    /**
     * Channel being used to receive incoming messages.
     * 
     * @var Channel
     */
    protected $messages;
    
    /**
     * Cancellation handler being used to cancel the incoming frame processing task.
     */
    protected $cancel;
    
    /**
     * Background context that runs the frame processing task.
     * 
     * @var Context
     */
    protected $background;
    
    /**
     * Create a new WebSocket connection.
     * 
     * @param Context $context Async execution context.
     * @param bool $client Establish a connection in client mode?
     * @param FramedStream $stream Stream being used to read and write frames.
     * @param string $protocol Negotiated application protocol.
     * @param PerMessageDeflate $deflate Compression extension.
     */
    public function __construct(Context $context, bool $client = true, FramedStream $stream, string $protocol = '', ?PerMessageDeflate $deflate = null)
    {
        $this->client = $client;
        $this->stream = $stream;
        $this->protocol = $protocol;
        
        $this->reader = new MessageReader($this->maxTextMessageSize, $deflate);
        $this->messages = new Channel();
        
        if ($deflate) {
            $this->deflate = $deflate;
            $this->compression = $deflate->getCompressionContext();
            $this->flushMode = $deflate->getCompressionFlushMode();
        }
        
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
     * @param Context $context Async execution context.
     * @param string $text The text to be sent (must be UTF-8 encoded).
     * @param int $priority Message priority.
     * @param bool $compress Use compression if available?
     * @return int Number of transmitted bytes.
     *
     * @throws \InvalidArgumentException When the given message is not UTF-8 encoded.
     */
    public function sendText(Context $context, string $text, bool $compress = false, int $priority = 0): Promise
    {
        if (!\preg_match('//u', $text)) {
            return new \InvalidArgumentException('Message is not UTF-8 encoded');
        }
        
        $compress = $compress && $this->deflate;
        
        return $this->executor->submit($context, function (Context $context) use ($text, $compress) {
            $type = Frame::TEXT;
            $reserved = $compress ? Frame::RESERVED1 : 0;
            $len = 0;
            
            $chunks = \str_split($text, 8192);
            
            for ($size = \count($chunks) - 1, $i = 0; $i < $size; $i++) {
                if ($compress) {
                    $chunks[$i] = \deflate_add($this->compression, $chunks[$i], \ZLIB_SYNC_FLUSH);
                }
                
                $len += yield $this->stream->writeFrame($context, new Frame($type, $chunks[$i], false, $reserved));
                $type = Frame::CONTINUATION;
                
                if ($reserved) {
                    $reserved = 0;
                }
            }
            
            if ($compress) {
                $chunks = \substr(\deflate_add($this->compression, $chunks[$i], $this->flushMode), 0, -4);
            } else {
                $chunks = $chunks[$i];
            }
            
            return $len + yield $this->stream->writeFrame($context, new Frame($type, $chunks, true, $reserved));
        }, $priority);
    }
    
    /**
     * Send contents of the given stream as binary message.
     *
     * @param Context $context Async execution context.
     * @param ReadableStream $stream Source of data to be sent (will be closed after all bytes have been consumed).
     * @param bool $compress Use compression if available?
     * @param int $priority Message priority.
     * @return int Number of transmitted bytes.
     */
    public function sendBinary(Context $context, ReadableStream $stream, bool $compress = false, int $priority = 0): Promise
    {
        $compress = $compress && $this->deflate;
        
        return $this->executor->submit($context, function (Context $context) use ($stream, $compress) {
            $type = Frame::BINARY;
            $reserved = $compress ? Frame::RESERVED1 : 0;
            $len = 0;
            
            try {
                $chunk = yield $stream->readBuffer($context, 8192, false);
                
                if ($chunk === null) {
                    return $len;
                }
                
                while (null !== ($next = yield $stream->readBuffer($context, 8192, false))) {
                    if ($compress) {
                        $chunk = \deflate_add($this->compression, $chunk, \ZLIB_SYNC_FLUSH);
                    }
                    
                    $len += yield $this->stream->writeFrame($context, new Frame($type, $chunk, false, $reserved));
                    
                    $chunk = $next;
                    $type = Frame::CONTINUATION;
                    
                    if ($reserved) {
                        $reserved = 0;
                    }
                }
                
                if ($chunk !== null) {
                    if ($compress) {
                        $chunk = \substr(\deflate_add($this->compression, $chunk, \ZLIB_SYNC_FLUSH), 0, -4);
                    }
                    
                    $len += yield $this->stream->writeFrame($context, new Frame($type, $chunk, true, $reserved));
                }
            } finally {
                $stream->close();
            }
            
            return $len;
        }, $priority);
    }
    
    /**
     * Send a PING frame to the server and await the PONG frame response.
     * 
     * @param Context $context Async execution context.
     * @param int $timeout Maximum acceptable ping time in seconds.
     * @return float Roundtrip time of the ping in seconds.
     */
    public function ping(Context $context, int $timeout = 2): Promise
    {
        return $context->task(function (Context $context) use ($timeout) {
            yield $this->stream->writeFrame($context, new Frame(Frame::PING, $payload = \random_bytes(8)), 100);
            
            $time = \microtime(true);
            
            $this->pings[$payload] = new Placeholder($context->cancelAfter($timeout * 1000));
            
            try {
                yield $context->keepBusy($this->pings[$payload]->promise());
            } finally {
                unset($this->pings[$payload]);
            }
            
            return \microtime(true) - $time;
        });
    }
    
    /**
     * {@inheritdoc}
     */
    public function receive(Context $context, $eof = null): Promise
    {
        return $this->messages->receive($context, $eof);
    }
    
    /**
     * Coroutine that handles incoming WebSocket frames.
     */
    protected function processFrames(Context $context): \Generator
    {
        $message = null;
        $e = null;
        
        try {
            while (true) {
                $frame = yield from $this->stream->readFrame($context);
                
                switch ($frame->opcode) {
                    case Frame::PING:
                        $this->executor->submit($context, function (Context $context, $frame) {
                            return yield $this->stream->writeFrame($context, new Frame(Frame::PONG, $frame->data), 100);
                        }, 100);
                        continue 2;
                    case Frame::PONG:
                        if (isset($this->pings[$frame->data])) {
                            $this->pings[$frame->data]->resolve();
                        }
                        continue 2;
                    case Frame::CONNECTION_CLOSE:
                        break 2;
                }
                
                if ($message !== null) {
                    try {
                        yield $message;
                    } finally {
                        $message = null;
                    }
                }
                
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
                }
                
                if ($message !== null) {
                    $message = $this->messages->send($context, $message);
                }
            }
        } catch (\Throwable $e) {
            // Forward error to close operations.
        }
        
        try {
            $code = ($e instanceof ConnectionException) ? $e->getCode() : Frame::NORMAL_CLOSURE;
            
            yield $this->stream->writeFrame($context, new Frame(Frame::CONNECTION_CLOSE, \pack('n', $code)));
        } catch (\Throwable $ex) {
            // Ignore this error.
        }
        
        $this->stream->close($e);
        $this->reader->close($e);
        $this->messages->close($e);
    }
}
