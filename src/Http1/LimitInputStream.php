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

/**
 * Input stream with pre-defined limit.
 * 
 * @author Martin Schröder
 */
class LimitInputStream implements InputStreamInterface
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
     * @param InputStreamInterface $stream
     * @param int $limit
     * @param bool $cascadeClose
     */
    public function __construct(InputStreamInterface $stream, int $limit, bool $cascadeClose = true)
    {
        $this->stream = $stream;
        $this->limit = $limit;
        $this->cascadeClose = $cascadeClose;
    }
    
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if ($this->cascadeClose) {
            $this->stream->close();
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function eof(): bool
    {
        return $this->offset >= $this->limit || $this->stream->eof();
    }
    
    /**
     * {@inheritdoc}
     */
    public function read(int $length = 8192, float $timeout = 0): \Generator
    {
        if ($this->offset >= $this->limit || $this->stream->eof()) {
            return '';
        }
        
        $chunk = yield from $this->stream->read(min($length, $this->limit - $this->offset), $timeout);
        $this->offset += strlen($chunk);
        
        return $chunk;
    }
}
