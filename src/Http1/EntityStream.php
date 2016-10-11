<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Deferred;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\ReadableStreamDecorator;

class EntityStream extends ReadableStreamDecorator
{
    protected $defer;

    public function __construct(ReadableStream $stream, bool $cascadeClose = true)
    {
        parent::__construct($stream);
        
        $this->cascadeClose = $cascadeClose;
        $this->defer = new Deferred();
    }
    
    public function __destruct()
    {
        $this->close();
    }

    public function getAwaitable(): Awaitable
    {
        return $this->defer;
    }

    public function close(): Awaitable
    {
        $close = parent::close();
        
        $close->when(function () {
            if ($this->defer->isPending()) {
                $this->defer->resolve(null);
            }
        });
        
        return $close;
    }

    protected function processChunk(string $chunk): string
    {
        if ($chunk === '' && $this->defer->isPending()) {
            $this->defer->resolve(true);
        }
        
        return $chunk;
    }
}
