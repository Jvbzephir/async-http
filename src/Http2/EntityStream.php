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
use KoolKode\Async\Stream\ReadableChannelStream;
use KoolKode\Async\Util\Channel;

class EntityStream extends ReadableChannelStream
{
    protected $conn;

    protected $streamId;
    
    protected $windowSize;
    
    protected $threshold;

    protected $drained = 0;
    
    public function __construct(Channel $channel, Connection $conn, int $streamId, int $windowSize)
    {
        parent::__construct($channel);
        
        $this->conn = $conn;
        $this->streamId = $streamId;
        $this->windowSize = $windowSize;
        $this->threshold = \max(4087, $windowSize / 2);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): Awaitable
    {
        $this->channel->close();
        
        $close = parent::close();
        
        $close->when(function () {
            if ($this->drained > 0) {
                $this->updateWindow();
            }
            
            $this->conn->closeStream($this->streamId);
        });
        
        return $close;
    }

    protected function readNextChunk(): \Generator
    {
        while (null !== ($chunk = yield $this->channel->receive())) {
            if ($chunk !== '') {
                break;
            }
        }
        
        if ($chunk === null) {
            $this->conn->closeStream($this->streamId);
        } else {
            $this->drained += \strlen($chunk);
            
            if ($this->drained >= $this->threshold) {
                $this->updateWindow();
            }
        }
        
        return $chunk;
    }

    protected function updateWindow()
    {
        $frame = new Frame(Frame::WINDOW_UPDATE, \pack('N', $this->drained));
        $this->drained = 0;
        
        // Enque window update frames, do not await completion to allow for faster reads.
        $this->conn->writeFrame($frame, 100);
        $this->conn->writeStreamFrame($this->streamId, $frame, 100);
    }
}
