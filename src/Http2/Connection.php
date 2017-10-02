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

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\CancellationException;
use KoolKode\Async\Context;
use KoolKode\Async\Deferred;
use KoolKode\Async\Placeholder;
use KoolKode\Async\Promise;
use KoolKode\Async\Channel\Channel;
use KoolKode\Async\Channel\InputChannel;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Stream\StreamClosedException;

class Connection implements InputChannel
{
    public const CLIENT = 1;
    
    public const SERVER = 2;
    
    /**
     * Connection preface that must be sent by clients.
     *
     * @var string
     */
    public const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    
    /**
     * Connection preface HTTP body that is sent by clients.
     *
     * @var string
     */
    public const PREFACE_BODY = "SM\r\n\r\n";

    public const INITIAL_WINDOW_SIZE = 0xFFFF;

    public const SETTING_HEADER_TABLE_SIZE = 0x01;

    public const SETTING_ENABLE_PUSH = 0x02;

    public const SETTING_MAX_CONCURRENT_STREAMS = 0x03;

    public const SETTING_INITIAL_WINDOW_SIZE = 0x04;

    public const SETTING_MAX_FRAME_SIZE = 0x05;

    public const SETTING_MAX_HEADER_LIST_SIZE = 0x06;

    protected $mode;
    
    protected $stream;

    protected $hpack;
    
    protected $background;
    
    protected $cancel;
    
    protected $streams = [];
    
    protected $pings = [];
    
    protected $nextStreamId;
    
    protected $outputWindow = self::INITIAL_WINDOW_SIZE;
    
    protected $outputDefer;
    
    protected $channel;
    
    public function __construct(Context $context, int $mode, FramedStream $stream, HPack $hpack)
    {
        $this->mode = $mode;
        $this->stream = $stream;
        $this->hpack = $hpack;
        
        $context = $context->background();
        $context = $context->cancellable($this->cancel = $context->cancellationHandler());
        
        $this->background = $context;
        $this->nextStreamId = ($this->mode == self::CLIENT) ? 1 : 2;
        
        if ($this->mode == self::SERVER) {
            $this->channel = new Channel();
        }
        
        Context::rethrow($context->task($this->processFrames($context)));
    }
    
    /**
     * {@inheritdoc}
     */
    public function close(?\Throwable $e = null): void
    {
        $this->cancel->cancel('Connection closed', $e);
        
        if ($this->outputDefer) {
            $this->outputDefer->fail(new StreamClosedException('Connection has been closed', 0, $e));
        }
    }
    
    public function closeStream(int $id): void
    {
        if (isset($this->streams[$id])) {
            unset($this->streams[$id]);
        }
    }
    
    public function isServer(): bool
    {
        return $this->mode == self::SERVER;
    }
    
    public function windowUpdate(int $size, int $stream = 0): void
    {
        if ($size > 0) {
            if ($stream) {
                Context::rethrow($this->stream->writeFrames($this->background, [
                    new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', $size)),
                    new Frame(Frame::WINDOW_UPDATE, $stream, \pack('N', $size))
                ]));
            } else {
                Context::rethrow($this->stream->writeFrame($this->background, new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', $size))));
            }
        }
    }
    
    public function send(Context $context, HttpRequest $request): Promise
    {
        $stream = new Stream($context, $this->nextStreamId, $this, $this->stream, $this->hpack, self::INITIAL_WINDOW_SIZE);
        
        $this->streams[$this->nextStreamId] = $stream;
        $this->nextStreamId += 2;
        
        return $context->task($stream->sendRequest($context, $request));
    }
    
    /**
     * {@inheritdoc}
     */
    public function receive(Context $context, $eof = null): Promise
    {
        return $this->channel->receive($context, $eof);
    }
    
    public function ping(Context $context): Promise
    {
        $payload = \random_bytes(8);
        
        $placeholder = new Placeholder($context, function (Placeholder $p, string $reason, ?\Throwable $e = null) use ($payload) {
            unset($this->pings[$payload]);
            
            $p->fail(new CancellationException($reason, $e));
        });
        
        $this->pings[$payload] = $placeholder;
        
        $promise = $this->stream->writeFrame($context, new Frame(Frame::PING, 0, $payload));
        
        $promise->when(function (\Throwable $e = null) use ($placeholder, $payload) {
            if ($e) {
                $placeholder->fail($e);
            } else {
                $this->pings[$payload] = $placeholder;
            }
        });
        
        $start = \microtime(true);
        
        return $context->transform($context->keepBusy($placeholder->promise()), static function () use ($start) {
            return (int) \ceil(1000 * (\microtime(true) - $start));
        });
    }

    protected function processFrames(Context $context): \Generator
    {
        while (true) {
            $frame = yield $this->stream->readFrame($context);
            
            if ($frame->stream === 0) {
                switch ($frame->type) {
                    case Frame::CONTINUATION:
                    case Frame::DATA:
                    case Frame::HEADERS:
                    case Frame::PRIORITY:
                    case Frame::PUSH_PROMISE:
                    case Frame::RST_STREAM:
                        throw new ConnectionException($frame->getTypeName() . ' must not be sent to a connection', Frame::PROTOCOL_ERROR);
                    case Frame::GOAWAY:
                        return;
                    case Frame::PING:
                        $this->processPingFrame($context, $frame);
                        break;
                    case Frame::SETTINGS:
                        break;
                    case Frame::WINDOW_UPDATE:
                        $this->processWindowUpdateFrame($frame);
                        break;
                }
                
                continue;
            }
            
            switch ($frame->type) {
                case Frame::CONTINUATION:
                case Frame::GOAWAY:
                case Frame::PING:
                case Frame::SETTINGS:
                    throw new ConnectionException($frame->getTypeName() . ' must not be sent to a stream', Frame::PROTOCOL_ERROR);
                case Frame::PRIORITY:
                    continue 2;
                case Frame::RST_STREAM:
                    $this->closeStream($frame->stream);
                    continue 2;
            }
            
            if (null === ($stream = $this->streams[$frame->stream] ?? null)) {
                switch ($frame->type) {
                    case Frame::RST_STREAM:
                    case Frame::WINDOW_UPDATE:
                        continue 2;
                }
                
                if ($this->mode == self::SERVER) {
                    switch ($frame->type) {
                        case Frame::HEADERS:
                        case Frame::PRIORITY:
                            if (!($frame->stream & 1)) {
                                throw new ConnectionException('Invalid stream id provided by client', Frame::PROTOCOL_ERROR);
                            }
                            
                            $stream = new Stream($context, $frame->stream, $this, $this->stream, $this->hpack, self::INITIAL_WINDOW_SIZE);
                            
                            $this->streams[$frame->stream] = $stream;
                            break;
                        default:
                            throw new ConnectionException("Stream {$frame->stream} does not exist");
                    }
                } else {
                    throw new ConnectionException("Stream {$frame->stream} does not exist");
                }
            }
            
            $done = null;
            
            switch ($frame->type) {
                case Frame::HEADERS:
                    $stream->processHeadersFrame($frame);
                    $done = $frame->flags & Frame::END_HEADERS;
                    
                    break;
                case Frame::CONTINUATION:
                    $stream->processContinuationFrame($frame);
                    $done = $frame->flags & Frame::END_HEADERS;
                    
                    break;
                case Frame::DATA:
                    $stream->processDataFrame($frame);
                    break;
                case Frame::WINDOW_UPDATE:
                    $stream->processWindowUpdateFrame($frame);
                    break;
            }
            
            if ($done && $this->mode == self::SERVER) {
                yield $this->channel->send($context, $stream);
            }
        }
    }

    protected function processPingFrame(Context $context, Frame $frame): void
    {
        if (\strlen($frame->data) !== 8) {
            throw new ConnectionException('PING frame payload must consist of 8 octets', Frame::FRAME_SIZE_ERROR);
        }
        
        if ($frame->flags & Frame::ACK) {
            if (isset($this->pings[$frame->data])) {
                try {
                    $this->pings[$frame->data]->resolve();
                } finally {
                    unset($this->pings[$frame->data]);
                }
            }
        } else {
            Context::rethrow($this->stream->writeFrame($context, new Frame(Frame::PING, 0, $frame->data, Frame::ACK)));
        }
    }

    public function getOutputWindow(): int
    {
        return $this->outputWindow;
    }

    public function waitForWindowUpdate(): Promise
    {
        if ($this->outputDefer === null) {
            $this->outputDefer = new Deferred($this->background);
        }
        
        return $this->outputDefer->promise();
    }

    protected function processWindowUpdateFrame(Frame $frame)
    {
        $increment = unpack('N', "\x7F\xFF\xFF\xFF" & $frame->data)[1];
        
        if ($increment < 1) {
            throw new ConnectionException('WINDOW_UPDATE increment must be positive and bigger than 0', Frame::PROTOCOL_ERROR);
        }
        
        $this->outputWindow += $increment;
        
        if ($this->outputDefer) {
            $defer = $this->outputDefer;
            $this->outputDefer = null;
            
            $defer->resolve($increment);
        }
    }
}
