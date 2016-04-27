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
use KoolKode\Async\Stream\Stream;

class StreamBody implements HttpBodyInterface
{
    protected $stream;
    
    public function __construct(InputStreamInterface $stream)
    {
        $this->stream = $stream;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getSize(): \Generator
    {
        yield NULL;
        
        return;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getInputStream(): \Generator
    {
        yield NULL;
        
        return $this->stream;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getContents(): \Generator
    {
        return yield from Stream::readContents($this->stream);
    }
}
