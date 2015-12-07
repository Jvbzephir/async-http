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

use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\SocketException;

/**
 * Stream that allows peeking reads while in cache mode.
 * 
 * @author Martin Schröder
 */
class DirectUpgradeDuplexStream implements DuplexStreamInterface
{
    /**
     * Decorated duplex stream.
     *
     * @var DuplexStreamInterface
     */
    protected $stream;
    
    /**
     * Current read offste.
     * 
     * @var int
     */
    protected $offset = 0;
    
    /**
     * Current read buffer.
     *
     * @var string
     */
    protected $buffer;
    
    /**
     * Cache flag, passed in by referenc using the constructor.
     * 
     * @var bool
     */
    protected $cache;
    
    /**
     * Create a read-buffered duplex stream decorator.
     *
     * @param DuplexStreamInterface $stream
     * @param bool $cache Cache flag, passed by reference to prevent modification via stream object.
     * @param string $buffer Initial buffer contents.
     */
    public function __construct(DuplexStreamInterface $stream, bool & $cache, string $buffer = '')
    {
        $this->stream = $stream;
        $this->cache = & $cache;
        $this->buffer = '';
    }
    
    public function __debugInfo(): array
    {
        $data = get_object_vars($this);
        $data['buffer'] = sprintf('%u bytes buffered', strlen($data['buffer']));
    
        return $data;
    }
    
    /**
     * Rewind the stream while in caching mode.
     * 
     * @throws SocketException When called with caching disabled.
     */
    public function rewind()
    {
        if (!$this->cache) {
            throw new SocketException('Cannot rewind stream when caching is disabled');
        }
        
        $this->offset = 0;
    }
    
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if ($this->cache) {
            throw new SocketException('Cannot close stream while cache is enabled');
        }
        
        return $this->stream->close();
    }
    
    /**
     * {@inheritdoc}
     */
    public function eof(): \Generator
    {
        return $this->offset >= strlen($this->buffer) && yield from $this->stream->eof();
    }
    
    /**
     * {@inheritdoc}
     */
    public function read(int $length = 8192): \Generator
    {
        $read = 0;
        
        if ($this->offset < strlen($this->buffer)) {
            $available = strlen($this->buffer) - $this->offset;
            
            if ($available >= $length) {
                try {
                    return substr($this->buffer, $this->offset, min($length, $available));
                } finally {
                    $this->offset += min($length, $available);
                }
            }
            
            $read = $available;
        }
        
        if ($this->cache) {
            $this->buffer .= yield from $this->stream->read($length - $read);
            
            $chunk = substr($this->buffer, $this->offset, $length);
            $this->offset += strlen($chunk);
            
            return $chunk;
        }
        
        $chunk = substr($this->buffer, $this->offset) . yield from $this->stream->read($length - $read);
        $this->offset += strlen($chunk);
        
        return $chunk;
    }
    
    /**
     * {@inheritdoc}
     */
    public function write(string $data, int $priority = 0): \Generator
    {
        if ($this->cache) {
            throw new SocketException('Cannot write data to stream while cache is enabled');
        }
        
        return yield from $this->stream->write($data, $priority);
    }
}
