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

class StringBody implements HttpBodyInterface
{
    protected $contents;
    
    public function __construct(string $contents = '')
    {
        $this->contents = $contents;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getSize(): \Generator
    {
        yield NULL;
        
        return strlen($this->contents);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getInputStream(): \Generator
    {
        yield NULL;
        
        return new StringInputStream($this->contents);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getContents(): \Generator
    {
        yield NULL;
        
        return $this->contents;
    }
}
