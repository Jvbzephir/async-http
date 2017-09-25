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
use KoolKode\Async\Placeholder;
use KoolKode\Async\Promise;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Loop\Loop;


class Connection
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
    
    protected $context;
    
    protected $task;
    
    protected $streams = [];
    
    protected $pings = [];
    
    protected $nextStreamId;
    
    protected $busyCount = 0;
    
    protected $busyWatcher;
    
    public function __construct(Loop $loop, int $mode, FramedStream $stream, HPack $hpack)
    {
        $this->mode = $mode;
        $this->stream = $stream;
        $this->hpack = $hpack;
        
        $this->nextStreamId = ($this->mode == self::CLIENT) ? 1 : 2;
        
        $this->context = (new Context($loop))->unreference();
        $this->task = Context::rethrow($this->context->task($this->processFrames($this->context)));
    }

    public function __destruct()
    {
        if ($this->busyWatcher) {
            $this->context->getLoop()->cancel($this->busyWatcher);
        }
    }
    
    public function close(): void
    {
        
    }
    
    public function closeStream(int $id): void
    {
        if (isset($this->streams[$id])) {
            unset($this->streams[$id]);
        }
    }
    
    public function windowUpdate(int $size, int $stream = 0): void
    {
        $this->stream->writeFrame($this->context, new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', $size)));
        
        if ($stream) {
            $this->stream->writeFrame($this->context, new Frame(Frame::WINDOW_UPDATE, $stream, \pack('N', $size)));
        }
    }
    
    public function busyWait(Context $context, Promise $promise): \Generator
    {
        if (++$this->busyCount === 1) {
            if ($this->busyWatcher === null) {
                $this->busyWatcher = $this->context->getLoop()->repeat(100000000, static function () {});
            } else {
                $this->context->getLoop()->enable($this->busyWatcher);
            }
        }
        
        try {
            $result = yield $promise;
        } finally {
            if (--$this->busyCount === 0) {
                $this->context->getLoop()->disable($this->busyWatcher);
            }
        }
        
        return $result;
    }
    
    public function send(Context $context, HttpRequest $request): Promise
    {
        $this->streams[$this->nextStreamId] = $stream = new Stream($this->nextStreamId, $this, $this->stream, $this->hpack);
        $this->nextStreamId += 2;
        
        return $context->task($stream->sendRequest($context, $request));
    }
    
    public function acceptStream(Context $context): Promise
    {
        
    }
    
    public function ping(Context $context): Promise
    {
        $payload = \random_bytes(8);
        
        $placeholder = new Placeholder(function (Placeholder $p, string $reason, ?\Throwable $e = null) use ($payload) {
            unset($this->pings[$payload]);
            
            $placeholder->fail(new CancellationException($reason, $e));
        });
        
        $this->stream->writeFrame(new Frame(Frame::PING, 0, $payload))->when(function (\Throwable $e = null) use ($placeholder, $payload) {
            if ($e) {
                $placeholder->fail($e);
            } else {
                $this->pings[$payload] = $placeholder;
            }
        });
        
        return $placeholder;
    }

    protected function processFrames(Context $context): \Generator
    {
        yield null;
        
        while (true) {
            $frame = yield $this->stream->readFrame($context);
            echo $frame, "\n";
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
            
            if (empty($this->streams[$frame->stream])) {
                throw new ConnectionException("Stream {$frame->stream} does not exist");
            }
            
            switch ($frame->type) {
                case Frame::HEADERS:
                    $this->streams[$frame->stream]->processHeadersFrame($frame);
                    break;
                case Frame::CONTINUATION:
                    $this->streams[$frame->stream]->processContinuationFrame($frame);
                    break;
                case Frame::DATA:
                    $this->streams[$frame->stream]->processDataFrame($frame);
                    break;
                case Frame::WINDOW_UPDATE:
                    $this->streams[$frame->stream]->processWindowUpdateFrame($frame);
                    break;
            }
        }
    }

    protected function processPingFrame(Context $context, Frame $frame): \Generator
    {
        if (\strlen($frame->data) !== 8) {
            throw new ConnectionException('PING frame payload must consist of 8 octets', Frame::FRAME_SIZE_ERROR);
        }
        
        if ($frame->flags & Frame::ACK) {
            if (isset($this->pings[$frame->data])) {
                $ping = $this->pings[$frame->data];
                unset($this->pings[$frame->data]);
                
                $ping->resolve(true);
            }
        } else {
            yield $this->stream->writeFrame($context, new Frame(Frame::PING, 0, $frame->data, Frame::ACK));
        }
    }
}
