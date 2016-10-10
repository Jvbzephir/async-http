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
    }
    
    public function shutdown(): Awaitable
    {
        $this->processor->cancel(new \RuntimeException('Connection shutdown'));
        
        return $this->processor;
    }
    
    public function getHPack(): HPack
    {
        return $this->hpack;
    }
    
    public function startClient(): Awaitable
    {
        return new Coroutine(function () {
            yield $this->socket->write(self::PREFACE);
            
            // Adjust initial window size.
            yield $this->writeFrame(new Frame(Frame::SETTINGS, \pack('nN', self::SETTING_INITIAL_WINDOW_SIZE, self::INITIAL_WINDOW_SIZE)));
            
            // Disable connection-level flow control.
            yield $this->writeFrame(new Frame(Frame::WINDOW_UPDATE, \pack('N', 0x7FFFFFFF - self::INITIAL_WINDOW_SIZE)));
            
            $this->processor = new Coroutine($this->handleIncomingFrames());
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
    
    protected function handleIncomingFrames()
    {
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
                
            } else {
                $this->streams[$stream]->processFrame($frame);
            }
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
