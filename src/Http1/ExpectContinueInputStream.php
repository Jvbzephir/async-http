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
use KoolKode\Async\Stream\InputStreamInterface;

/**
 * Input stream that signals the HTTP client to continue as soon as data is being read.
 * 
 * @author Martin Schröder
 */
class ExpectContinueInputStream implements InputStreamInterface
{
    /**
     * HTTP body input stream.
     * 
     * @var DuplexStreamInterface
     */
    protected $stream;
    
    /**
     * HTTP protocol version.
     * 
     * @var string
     */
    protected $version;
    
    /**
     * Has the body stream been continued?
     * 
     * @var bool
     */
    protected $continued = false;
    
    /**
     * Create a new HTTP input stream that signals the client to continue when data is being read.
     * 
     * @param DuplexStreamInterface $stream
     * @param string $version HTTP protocol version.
     */
    public function __construct(DuplexStreamInterface $stream, string $version)
    {
        $this->stream = $stream;
        $this->version = $version;
    }
    
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        return $this->stream->close();
    }
    
    /**
     * {@inheritdoc}
     */
    public function eof(): bool
    {
        return $this->continued && $this->stream->eof();
    }
    
    /**
     * {@inheritdoc}
     */
    public function read(int $length = 8192, float $timeout = 0): \Generator
    {
        if (!$this->continued) {
            $this->continued = true;
            
            yield from $this->stream->write(sprintf("HTTP/%s 100 Continue\r\n", $this->version));
        }
        
        return $this->stream->read($length, $timeout);
    }
}
