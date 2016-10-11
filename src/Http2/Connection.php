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

use KoolKode\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Success;
use KoolKode\Async\Util\Executor;

class Connection
{
    /**
     * Connection preface that must be sent by the client.
     *
     * @var string
     */
    const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    
    /**
     * Initial window is 64 KB.
     *
     * @var int
     */
    const INITIAL_WINDOW_SIZE = 65536;
    
    /**
     * PING frame payload.
     *
     * @var string
     */
    const PING_PAYLOAD = 'KoolKode';
    
    const SETTING_HEADER_TABLE_SIZE = 0x01;
    
    const SETTING_ENABLE_PUSH = 0x02;
    
    const SETTING_MAX_CONCURRENT_STREAMS = 0x03;
    
    const SETTING_INITIAL_WINDOW_SIZE = 0x04;
    
    const SETTING_MAX_FRAME_SIZE = 0x05;
    
    const SETTING_MAX_HEADER_LIST_SIZE = 0x06;
    
    protected $socket;
    
    protected $hpack;
    
    protected $writer;
    
    protected $processor;
    
    protected $nextStreamId = 1;
    
    protected $streams = [];
    
    public function __construct(DuplexStream $socket, HPack $hpack)
    {
        $this->socket = $socket;
        $this->hpack = $hpack;
        
        $this->writer = new Executor();
        $this->processor = new Coroutine($this->processIncomingFrames());
    }

    public function shutdown(): Awaitable
    {
        if ($this->processor) {
            $this->processor->cancel(new \RuntimeException('Connection shutdown'));
            
            return $this->processor;
        }
        
        return new Success(null);
    }
    
    public function getHPack(): HPack
    {
        return $this->hpack;
    }
    
    public static function connectClient(DuplexStream $socket, HPack $hpack): Awaitable
    {
        return new Coroutine(function () use ($socket, $hpack) {
            $conn = new Connection($socket, $hpack);
            
            yield $socket->write(self::PREFACE);
            
            // Adjust initial window size.
            yield $conn->writeFrame(new Frame(Frame::SETTINGS, \pack('nN', self::SETTING_INITIAL_WINDOW_SIZE, self::INITIAL_WINDOW_SIZE)));
            
            // Disable connection-level flow control.
            yield $conn->writeFrame(new Frame(Frame::WINDOW_UPDATE, \pack('N', 0x7FFFFFFF - self::INITIAL_WINDOW_SIZE)));
            
            return $conn;
        });
    }
    
    public function openStream(): Stream
    {
        try {
            return $this->streams[$this->nextStreamId] = new Stream($this->nextStreamId, $this);
        } finally {
            $this->nextStreamId += 2;
        }
    }
    
    public function closeStream(int $streamId)
    {
        if (isset($this->streams[$streamId])) {
            unset($this->streams[$streamId]);
        }
    }
    
    protected function processIncomingFrames()
    {
        try {
            while (true) {
                $header = yield $this->socket->readBuffer(9, true);
                
                $length = \unpack('N', "\x00" . $header)[1];
                $type = \ord($header[3]);
                $stream = \unpack('N', "\x7F\xFF\xFF\xFF" & \substr($header, 5, 4))[1];
                
                if ($length > 0) {
                    $frame = new Frame($type, yield $this->socket->readBuffer($length, true), \ord($header[4]));
                } else {
                    $frame = new Frame($type, '', \ord($header[4]));
                }
                
                if ($stream === 0) {
                    switch ($frame->type) {
                        case Frame::PING:
                            yield from $this->processPingFrame($frame);
                            break;
                        default:
                            if ($this->processFrame($frame)) {
                                break 2;
                            }
                    }
                } else {
                    yield from $this->processStreamFrame($stream, $frame);
                }
            }
        } finally {
            $this->processor = null;
            
            try {
                foreach ($this->streams as $stream) {
                    $stream->close();
                }
            } finally {
                $this->streams = [];
            }
        }
    }

    protected function processFrame(Frame $frame)
    {
        switch ($frame->type) {
            case Frame::CONTINUATION:
                throw new ConnectionException('CONTINUATION frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::DATA:
                throw new ConnectionException('DATA frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::GOAWAY:
                return true;
            case Frame::HEADERS:
                throw new ConnectionException('HEADERS frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::PRIORITY:
                throw new ConnectionException('PRIORITY frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::PUSH_PROMISE:
                throw new ConnectionException('PUSH_PROMISE is not supported by this server', Frame::PROTOCOL_ERROR);
            case Frame::RST_STREAM:
                throw new ConnectionException('RST_STREAM frame must not be sent to connection', Frame::PROTOCOL_ERROR);
            case Frame::SETTINGS:
                // TODO: Implement HTTP/2 settings.
                break;
            case Frame::WINDOW_UPDATE:
                // TODO: Implement HTTP/2 flow control.
                break;
        }
    }
    
    protected function processPingFrame(Frame $frame): \Generator
    {
        if (\strlen($frame->data) !== 8) {
            throw new ConnectionException('PING frame payload must consist of 8 octets', Frame::FRAME_SIZE_ERROR);
        }
        
        if ($frame->flags & Frame::ACK) {
            if ($frame->data !== self::PING_PAYLOAD) {
                throw new ConnectionException('Invalid response to PING received', Frame::PROTOCOL_ERROR);
            }
        } else {
            yield $this->writeFrame(new Frame(Frame::PING, $frame->data, Frame::ACK), 500);
        }
    }

    protected function processStreamFrame(int $stream, Frame $frame): \Generator
    {
        if (empty($this->streams[$stream])) {
            switch ($frame->type) {
                case Frame::PRIORITY:
                case Frame::RST_STREAM:
                    return;
                default:
                    throw new ConnectionException(\sprintf('Stream does not exist: "%s"', $stream));
            }
        }
        
        switch ($frame->type) {
            case Frame::HEADERS:
                $this->streams[$stream]->processHeadersFrame($frame);
                break;
            case Frame::CONTINUATION:
                $this->streams[$stream]->processContinuationFrame($frame);
                break;
            case Frame::DATA:
                yield from $this->streams[$stream]->processDataFrame($frame);
                break;
            default:
                $this->streams[$stream]->processFrame($frame);
        }
    }

    public function writeFrame(Frame $frame, int $priority = 0): Awaitable
    {
        return $this->writer->execute(function () use ($frame) {
            return $this->socket->write($frame->encode(0));
        }, $priority);
    }

    public function writeStreamFrame(int $stream, Frame $frame, int $priority = 0): Awaitable
    {
        return $this->writer->execute(function () use ($stream, $frame) {
            return $this->socket->write($frame->encode($stream));
        }, $priority);
    }

    public function writeStreamFrames(int $stream, array $frames, int $priority = 0): Awaitable
    {
        return $this->writer->execute(function () use ($stream, $frames) {
            foreach ($frames as $frame) {
                yield $this->socket->write($frame->encode($stream));
            }
        }, $priority);
    }
}
