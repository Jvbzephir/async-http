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

use KoolKode\Async\Stream\StringInputStream;

use function KoolKode\Async\noop;

/**
 * HTTP message body wrapping a string.
 * 
 * @author Martin Schröder
 */
class StringBody implements HttpBodyInterface
{
    /**
     * Message body.
     * 
     * @var string
     */
    protected $contents;
  
    /**
     * Create a message body around the given contents.
     * 
     * @param string $contents
     */
    public function __construct(string $contents = '')
    {
        $this->contents = $contents;
    }
    
    /**
     * Dump the message body.
     * 
     * @return string
     */
    public function __toString()
    {
        return $this->contents;
    }
    
    /**
     * {@inheritdoc}
     */
    public function isCached(): bool
    {
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function prepareMessage(HttpMessage $message): HttpMessage
    {
        return $message;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getSize(): \Generator
    {
        yield noop();
        
        return strlen($this->contents);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getInputStream(): \Generator
    {
        yield noop();
        
        return new StringInputStream($this->contents);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getContents(): \Generator
    {
        yield noop();
        
        return $this->contents;
    }
}
