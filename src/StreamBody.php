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

use KoolKode\Async\Stream\CachedInputStream;
use KoolKode\Async\Stream\InputStreamInterface;
use KoolKode\Async\Stream\Stream;

use function KoolKode\Async\noop;

/**
 * HTTP message body based on an input stream.
 * 
 * @author Martin Schröder
 */
class StreamBody implements HttpBodyInterface
{
    /**
     * Body data stream.
     * 
     * @var InputStreamInterface
     */
    protected $stream;
    
    /**
     * Create message body backed by the given stream.
     * 
     * @param InputStreamInterface $stream
     */
    public function __construct(InputStreamInterface $stream)
    {
        $this->stream = $stream;
    }
    
    /**
     * {@inheritdoc}
     */
    public function isCached(): bool
    {
        return $this->stream instanceof CachedInputStream;
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
        
        return;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getInputStream(): \Generator
    {
        yield noop();
        
        if ($this->stream instanceof CachedInputStream && $this->stream->eof()) {
            $this->stream->rewind();
        }
        
        return $this->stream;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getContents(): \Generator
    {
        if ($this->stream instanceof CachedInputStream && $this->stream->eof()) {
            $this->stream->rewind();
        }
        
        return yield from Stream::readContents($this->stream);
    }
}
