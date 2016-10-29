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
use KoolKode\Async\AwaitPending;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Deferred;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\ReadableChannelStream;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Success;
use KoolKode\Async\Util\Channel;
use KoolKode\Async\Util\Executor;

class Connection
{
    protected $socket;
    
    protected $client;
    
    protected $processor;
    
    protected $messages;
    
    protected $writer;
    
    protected $buffer;
    
    protected $maxFrameSize = 0xFFFF;
    
    protected $maxTextMessageSize = 0x80000;
    
    protected $pings = [];
    
    public function __construct(SocketStream $socket, bool $client = true)
    {
        $this->socket = $socket;
        $this->client = $client;
        
        $this->messages = new Channel(100);
        $this->writer = new Executor();
        
        $this->processor = new Coroutine($this->processIncomingFrames(), true);
    }

    public function shutdown(): Awaitable
    {
        if ($this->processor) {
            try {
                return new AwaitPending($this->processor->cancel(new \RuntimeException('WebSocket connection shutdown')));
            } finally {
                $this->processor = null;
            }
        }
        
        return new Success(null);
    }
    
    public function ping(): Awaitable
    {
        $payload = \random_bytes(8);
        
        $defer = new Deferred(function () use ($payload) {
            unset($this->pings[$payload]);
        });
        
        $this->sendFrame(new Frame(Frame::PING, $payload), 1000)->when(function (\Throwable $e = null) use ($defer, $payload) {
            if ($e) {
                $defer->fail($e);
            } else {
                $this->pings[$payload] = $defer;
            }
        });
        
        return $defer;
    }
    
    public function readNextMessage(): Awaitable
    {
        return $this->messages->receive();
    }

    public function sendText(string $text, int $priority = 0): Awaitable
    {
        return $this->writer->execute(function () use ($text) {
            $chunks = \str_split($text, 4092);
            
            for ($size = \count($chunks) - 1, $i = 0; $i < $size; $i++) {
                yield $this->writeFrame(new Frame(Frame::TEXT, $chunks[$i], false));
            }
            
            yield $this->writeFrame(new Frame(Frame::TEXT, $chunks[$i]));
        }, $priority);
    }

    public function sendBinary(ReadableStream $stream, int $priority = 0): Awaitable
    {
        return $this->writer->execute(function () use ($stream) {
            try {
                $chunk = yield $stream->readBuffer(4092);
                
                while (null !== ($next = yield $stream->readBuffer(4092))) {
                    yield $this->writeFrame(new Frame(Frame::BINARY, $chunk, false));
                    
                    $chunk = $next;
                }
                
                if ($chunk !== null) {
                    yield $this->writeFrame(new Frame(Frame::BINARY, $chunk));
                }
            } finally {
                $stream->close();
            }
        }, $priority);
    }

    protected function sendFrame(Frame $frame, int $priority = 0): Awaitable
    {
        return $this->writer->execute(function () use ($frame) {
            return $this->writeFrame($frame);
        }, $priority);
    }

    protected function writeFrame(Frame $frame): Awaitable
    {
        return $this->socket->write($frame->encode($this->client ? \random_bytes(4) : null));
    }

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
                    
                    yield $this->sendFrame(new Frame(Frame::CONNECTION_CLOSE, \pack('n', $reason)));
                }
            } finally {
                $this->socket->close();
            }
        }
    }

    protected function handleControlFrame(Frame $frame): \Generator
    {
        switch ($frame->opcode) {
            case Frame::CONNECTION_CLOSE:
                return false;
            case Frame::PING:
                yield $this->sendFrame(new Frame(Frame::PONG, $frame->data), 1000);
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

    protected function handleTextFrame(Frame $frame): \Generator
    {
        if ($this->buffer !== null) {
            throw new ConnectionException('Cannot receive new message while reading continuation frames', Frame::PROTOCOL_ERROR);
        }
        
        if (\strlen($frame->data) > $this->maxTextMessageSize) {
            throw new ConnectionException(\sprintf('Maximum text message size of %u bytes exceeded', $this->maxTextMessageSize), Frame::MESSAGE_TOO_BIG);
        }
        
        if ($frame->finished) {
            if (!\preg_match('//u', $frame->data)) {
                throw new ConnectionException('Text message contains invalid UTF-8 data', Frame::INCONSISTENT_MESSAGE);
            }
            
            yield $this->messages->send($frame->data);
        } else {
            $this->buffer = $frame->data;
        }
    }

    protected function handleBinaryFrame(Frame $frame): \Generator
    {
        if ($this->buffer !== null) {
            throw new ConnectionException('Cannot receive new message while reading continuation frames', Frame::PROTOCOL_ERROR);
        }
        
        if ($frame->finished) {
            yield $this->messages->send(new ReadableMemoryStream($frame->data));
        } else {
            $this->buffer = new Channel(16);
            
            yield $this->messages->send(new ReadableChannelStream($this->buffer));
            
            foreach (\str_split($frame->data, 4096) as $chunk) {
                yield $this->buffer->send($chunk);
            }
        }
    }

    protected function handleContinuationFrame(Frame $frame): \Generator
    {
        if ($this->buffer === null) {
            throw new ConnectionException('Continuation frame received outside of a message', Frame::PROTOCOL_ERROR);
        }
        
        try {
            if ($this->buffer instanceof Channel) {
                foreach (\str_split($frame->data, 4096) as $chunk) {
                    yield $this->buffer->send($chunk);
                }
                
                if ($frame->finished) {
                    $this->buffer->close();
                }
            } else {
                if ((\strlen($this->buffer) + \strlen($frame->data)) > $this->maxTextMessageSize) {
                    throw new ConnectionException(\sprintf('Maximum text message size of %u bytes exceeded', $this->maxTextMessageSize), Frame::MESSAGE_TOO_BIG);
                }
                
                $this->buffer .= $frame->data;
                
                if ($frame->finished) {
                    if (!\preg_match('//u', $this->buffer)) {
                        throw new ConnectionException('Text message contains invalid UTF-8 data', Frame::INCONSISTENT_MESSAGE);
                    }
                    
                    yield $this->messages->send($this->buffer);
                }
            }
        } catch (\Throwable $e) {
            if ($this->buffer instanceof Channel) {
                $this->buffer->close($e);
            }
            
            $this->buffer = null;
        } finally {
            if ($frame->finished) {
                $this->buffer = null;
            }
        }
    }

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
        
        return new Frame($byte1 & Frame::OPCODE, $data, ($byte1 & Frame::FINISHED) ? true : false);
    }
}
