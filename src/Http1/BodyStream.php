<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Success;
use KoolKode\Async\Util\Channel;

class BodyStream implements ReadableStream
{
    protected $stream;
    
    protected $cascadeClose;
    
    public function __construct(ReadableStream $stream, bool $cascadeClose = true)
    {
        $this->stream = $stream;
        $this->cascadeClose = $cascadeClose;
    }

    public function close(): Awaitable
    {
        if ($this->cascadeClose && $this->stream !== null) {
            try {
                return $this->stream->close();
            } finally {
                $this->stream = null;
            }
        }
        
        return new Success(null);
    }
    
    /**
     * {@inheritdoc}
     */
    public function read(int $length = 8192): Awaitable
    {
        return $this->stream->read($length);
    }
    
    /**
     * {@inheritdoc}
     */
    public function readBuffer(int $length, bool $enforceLength = false): Awaitable
    {
        return $this->stream->readBuffer($length, $enforceLength);
    }
    
    /**
     * {@inheritdoc}
     */
    public function readLine(int $length = 8192): Awaitable
    {
        return $this->stream->readLine($length);
    }
    
    /**
     * {@inheritdoc}
     */
    public function readTo(string $delim, int $length = 8192): Awaitable
    {
        return $this->stream->readTo($delim, $length);
    }
    
    /**
     * {@inheritdoc}
     */
    public function channel(int $chunkSize = 4096, int $length = null): Channel
    {
        return $this->stream->channel($chunkSize, $length);
    }
}
