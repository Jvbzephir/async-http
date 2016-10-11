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
use KoolKode\Async\Deferred;
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
    const INITIAL_WINDOW_SIZE = 65535;
    
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
    
    protected $client;
    
    protected $nextStreamId;
    
    protected $streams = [];
    
    protected $outputWindow = self::INITIAL_WINDOW_SIZE;
    
    protected $outputDefer;
    
    protected $localSettings = [
        self::SETTING_ENABLE_PUSH => 0,
        self::SETTING_MAX_CONCURRENT_STREAMS => 256,
        self::SETTING_INITIAL_WINDOW_SIZE => 65535,
        self::SETTING_MAX_FRAME_SIZE => 16384
    ];
    
    protected $remoteSettings = [
        self::SETTING_HEADER_TABLE_SIZE => 4096,
        self::SETTING_ENABLE_PUSH => 1,
        self::SETTING_MAX_CONCURRENT_STREAMS => 100,
        self::SETTING_INITIAL_WINDOW_SIZE => 65535,
        self::SETTING_MAX_FRAME_SIZE => 16384,
        self::SETTING_MAX_HEADER_LIST_SIZE => 16777216
    ];
    
    public function __construct(DuplexStream $socket, HPack $hpack, bool $client)
    {
        $this->socket = $socket;
        $this->hpack = $hpack;
        $this->client = $client;
        $this->nextStreamId = $client ? 1 : 2;
        
        $this->writer = new Executor();
    }

    public function shutdown(): Awaitable
    {
        if ($this->processor !== null) {
            $this->processor->cancel(new \RuntimeException('Connection shutdown'));
            
            return $this->processor;
        }
        
        return new Success(null);
    }
    
    public function getHPack(): HPack
    {
        return $this->hpack;
    }

    public function getRemoteSetting(int $setting): int
    {
        if (isset($this->remoteSettings[$setting])) {
            return $this->remoteSettings[$setting];
        }
        
        throw new \OutOfBoundsException(\sprintf('Remote setting not found: "%s"', $setting));
    }
    
    public static function connectClient(DuplexStream $socket, HPack $hpack): Awaitable
    {
        return new Coroutine(function () use ($socket, $hpack) {
            $conn = new Connection($socket, $hpack, true);
            
            yield $socket->write(self::PREFACE);
            
            $settings = '';
            
            foreach ($conn->localSettings as $k => $v) {
                $settings .= \pack('nN', $k, $v);
            }
            
            yield $conn->writeFrame(new Frame(Frame::SETTINGS, $settings));
            yield $conn->writeFrame(new Frame(Frame::WINDOW_UPDATE, \pack('N', 0x0FFFFFFF)));
            
            list ($stream, $frame) = yield from $conn->readNextFrame();
            
            if ($stream !== 0 || $frame->type !== Frame::SETTINGS) {
                throw new \RuntimeException('Failed to establish HTTP/2 connection');
            }
            
            $frames = new \SplQueue();
            $frames->enqueue([
                $stream,
                $frame
            ]);
            
            $conn->processor = new Coroutine($conn->processIncomingFrames($frames));
            
            return $conn;
        });
    }
    
    public function openStream(): Stream
    {
        try {
            return $this->streams[$this->nextStreamId] = new Stream($this->nextStreamId, $this, $this->remoteSettings[self::SETTING_INITIAL_WINDOW_SIZE]);
        } finally {
            $this->nextStreamId += 2;
        }
    }
    
    public function closeStream(int $streamId)
    {
        if (isset($this->streams[$streamId])) {
            $this->streams[$streamId]->close();
            
            unset($this->streams[$streamId]);
        }
    }
    
    protected function readNextFrame(): \Generator
    {
        $header = yield $this->socket->readBuffer(9, true);
        
        $length = \unpack('N', "\x00" . $header)[1];
        $type = \ord($header[3]);
        $stream = \unpack('N', "\x7F\xFF\xFF\xFF" & \substr($header, 5, 4))[1];
        
        if ($length > 0) {
            $frame = new Frame($type, yield $this->socket->readBuffer($length, true), \ord($header[4]));
        } else {
            $frame = new Frame($type, '', \ord($header[4]));
        }
        
        return [
            $stream,
            $frame
        ];
    }

    protected function processIncomingFrames(\SplQueue $frames = null): \Generator
    {
        try {
            while (true) {
                if ($frames === null) {
                    list ($stream, $frame) = yield from $this->readNextFrame();
                } else {
                    list ($stream, $frame) = $frames->dequeue();
                    
                    if ($frames->isEmpty()) {
                        $frames = null;
                    }
                }
                
                if ($stream === 0) {
                    switch ($frame->type) {
                        case Frame::PING:
                            yield from $this->processPingFrame($frame);
                            break;
                        case Frame::SETTINGS:
                            yield from $this->processSettingsFrame($frame);
                            break;
                        case Frame::WINDOW_UPDATE:
                            $this->processWindowUpdateFrame($frame);
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
            try {
                if ($this->outputDefer !== null) {
                    $this->outputDefer->fail(new \RuntimeException('HTTP/2 connection closed'));
                }
                
                try {
                    foreach ($this->streams as $stream) {
                        $stream->close();
                    }
                } finally {
                    $this->streams = [];
                }
                
                yield $this->writeFrame(new Frame(Frame::GOAWAY, ''));
            } finally {
                $this->socket->close();
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

    protected function processSettingsFrame(Frame $frame): \Generator
    {
        if ($frame->flags & Frame::ACK) {
            if ($frame->data !== '') {
                throw new ConnectionException('ACK SETTINGS frame must not have a length of more than 0 bytes', Frame::FRAME_SIZE_ERROR);
            }
            
            return;
        }
        
        if (\strlen($frame->data) % 6) {
            throw new ConnectionException('SETTINGS frame payload length must be a multiple of 6 ', Frame::FRAME_SIZE_ERROR);
        }
        
        foreach (\str_split($frame->data, 6) as $setting) {
            list ($key, $value) = \array_values(\unpack('nk/Nv', $setting));
            
            switch ($key) {
                case self::SETTING_ENABLE_PUSH:
                    if ($value !== 0 && $value !== 1) {
                        throw new ConnectionException('Invalid enable push setting received', Frame::PROTOCOL_ERROR);
                    }
                    
                    $this->remoteSettings[self::SETTING_ENABLE_PUSH] = $value ? 1 : 0;
                    break;
                case self::SETTING_HEADER_TABLE_SIZE:
                    if ($value < 4096) {
                        throw new ConnectionException('Header table size must be at least 4096 bytes', Frame::COMPRESSION_ERROR);
                    }
                    
                    $this->remoteSettings[self::SETTING_HEADER_TABLE_SIZE] = $value;
                    break;
                case self::SETTING_INITIAL_WINDOW_SIZE:
                    $this->remoteSettings[self::SETTING_INITIAL_WINDOW_SIZE] = $value;
                    break;
                case self::SETTING_MAX_CONCURRENT_STREAMS:
                    $this->remoteSettings[self::SETTING_MAX_CONCURRENT_STREAMS] = $value;
                    break;
                case self::SETTING_MAX_FRAME_SIZE:
                    if ($value < 16384) {
                        throw new ConnectionException('Max frame size must be at least 16384 bytes', Frame::PROTOCOL_ERROR);
                    }
                    
                    $this->remoteSettings[self::SETTING_MAX_FRAME_SIZE] = $value;
                    break;
                case self::SETTING_MAX_HEADER_LIST_SIZE:
                    $this->remoteSettings[self::SETTING_MAX_HEADER_LIST_SIZE] = $value;
                    break;
            }
        }
        
        yield $this->writeFrame(new Frame(Frame::SETTINGS, '', Frame::ACK), 100);
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

    public function getOutputWindow(): int
    {
        return $this->outputWindow;
    }

    public function awaitWindowUpdate(): Awaitable
    {
        if ($this->outputDefer === null) {
            $this->outputDefer = new Deferred();
        }
        
        return $this->outputDefer;
    }
    
    protected function processStreamFrame(int $stream, Frame $frame): \Generator
    {
        switch ($frame->type) {
            case Frame::PRIORITY:
            case Frame::RST_STREAM:
                return;
        }
        
        if (empty($this->streams[$stream])) {
            if (!$this->client) {
                switch ($frame->type) {
                    case Frame::HEADERS:
                    case Frame::WINDOW_UPDATE:
                        return $this->openStream()->processFrame($frame);
                }
            }
            
            throw new ConnectionException(\sprintf('Stream does not exist: "%s"', $stream));
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
            $len = 0;
            
            foreach ($frames as $frame) {
                $len += yield $this->socket->write($frame->encode($stream));
            }
            
            return $len;
        }, $priority);
    }
}
