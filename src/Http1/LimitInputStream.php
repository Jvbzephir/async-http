<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Stream\InputStreamInterface;
use KoolKode\Async\Stream\ResourceStreamInterface;
use KoolKode\Async\Stream\StreamClosedException;

/**
 * Input stream with pre-defined maximum number of bytes that can be read.
 * 
 * @author Martin Schröder
 */
class LimitInputStream implements InputStreamInterface, ResourceStreamInterface
{
    /**
     * Wrapped input stream.
     * 
     * @var InputStreamInterface
     */
    protected $stream;

    /**
     * Maximum number of bytes to be read.
     * 
     * @var int
     */
    protected $limit;

    /**
     * Current read offset.
     * 
     * @var int
     */
    protected $offset = 0;

    /**
     * Cascade call to close to wrapped stream?
     * 
     * @var bool
     */
    protected $cascadeClose;

    /**
     * Create a length-limited input stream.
     * 
     * @param InputStreamInterface $stream Wrapped input stream.
     * @param int $limit Maximum number of bytes to be read from the stream.
     * @param bool $cascadeClose Cascade call to close to wrapped stream?
     */
    public function __construct(InputStreamInterface $stream, int $limit, bool $cascadeClose = true)
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit must not be negative');
        }
        
        $this->stream = $stream;
        $this->limit = $limit;
        $this->cascadeClose = $cascadeClose;
    }

    /**
     * {@inheritdoc}
     */
    public function getResource()
    {
        return ($this->stream instanceof ResourceStreamInterface) ? $this->stream->getResource() : NULL;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if ($this->stream !== NULL) {
            try {
                if ($this->cascadeClose) {
                    $this->stream->close();
                }
            } finally {
                $this->stream = NULL;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function eof(): bool
    {
        if ($this->stream === NULL) {
            return true;
        }
        
        return $this->offset >= $this->limit || $this->stream->eof();
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length = 8192, float $timeout = 0): \Generator
    {
        if ($this->stream === NULL || $this->stream->eof()) {
            throw new StreamClosedException('Cannot read from closed stream');
        }
        
        if ($this->offset >= $this->limit) {
            throw new StreamClosedException(sprintf('Cannot read beyond %u bytes', $this->limit));
        }
        
        $chunk = yield from $this->stream->read(min($length, $this->limit - $this->offset), $timeout);
        $this->offset += \strlen($chunk);
        
        return $chunk;
    }
}
