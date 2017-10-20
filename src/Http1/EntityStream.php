<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Deferred;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\ReadableStreamDecorator;

class EntityStream extends ReadableStreamDecorator
{
    protected $defer;

    public function __construct(ReadableStream $stream, Deferred $defer, bool $cascadeClose = true)
    {
        parent::__construct($stream, $cascadeClose);
        
        $this->defer = $defer;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * {@inheritdoc}
     */
    public function close(?\Throwable $e = null): void
    {
        if ($this->defer) {
            $defer = $this->defer;
            $this->defer = null;
            
            $defer->resolve(false);
        }
        
        parent::close($e);
    }
    
    /**
     * {@inheritdoc}
     */
    protected function processChunk(string $chunk): string
    {
        if ($chunk === '' && $this->defer) {
            $defer = $this->defer;
            $this->defer = null;
            
            $defer->resolve(true);
        }
        
        return $chunk;
    }
}
