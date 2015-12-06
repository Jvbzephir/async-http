<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

use KoolKode\Async\Stream\InputStreamInterface;
use KoolKode\Async\SystemCall;

class HttpBody implements InputStreamInterface
{
    protected $first = true;
    
    protected $buffer = '';
    
    protected $body;
    
    public function __construct($body)
    {
        if (!$body instanceof \Iterator) {
            if (is_callable($body)) {
               $body = $body();
            }
        }
        
        if (!$body instanceof \Iterator) {
            throw new \InvalidArgumentException(sprintf('HTTP body requires an iterator / generator, given %s', is_object($body) ? get_class($body) : gettype($body)));
        }
        
        $this->body = $body;
    }
    
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->body = new \ArrayIterator([]);
    }
    
    /**
     * {@inheritdoc}
     */
    public function eof(): bool
    {
        return $this->buffer === '' && !$this->body->valid();
    }
    
    /**
     * {@inheritdoc}
     */
    public function read(int $length = 8192, bool $fillBuffer = false): \Generator
    {
        if ($fillBuffer) {
            while ($this->body->valid() && strlen($this->buffer) < $length) {
                $chunk = $this->readNextChunk();
            
                while ($chunk instanceof SystemCall) {
                    $chunk = $this->readNextChunk(yield $chunk);
                }
            
                if (is_string($chunk)) {
                    $this->buffer .= $chunk;
                }
            
                yield;
            }
            
            $chunk = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, strlen($chunk));
            
            return $chunk;
        }
        
        if ($this->buffer !== '') {
            $chunk = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, strlen($chunk));
            
            return $chunk;
        }
        
        while ($this->body->valid()) {
            $chunk = $this->readNextChunk();
            
            while ($chunk instanceof SystemCall) {
                $chunk = $this->readNextChunk(yield $chunk);
            }
            
            if (is_string($chunk)) {
                $this->buffer .= $chunk;
                
                $chunk = substr($this->buffer, 0, $length);
                $this->buffer = substr($this->buffer, strlen($chunk));
                
                return $chunk;
            }
            
            yield;
        }
        
        return '';
    }
    
    protected function readNextChunk($val = NULL)
    {
        if (!$this->body->valid()) {
            return '';
        }
        
        if ($this->body instanceof \Generator) {
            if ($this->first) {
                try {
                    return $this->body->current();
                } finally {
                    $this->first = false;
                }
            } else {
                return $this->body->send($val);
            }
        }
        
        $chunk = $this->body->current();
        $this->body->next();
        
        return $chunk;
    }
}
