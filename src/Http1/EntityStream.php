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
use KoolKode\Async\Stream\WritableStream;

/**
 * Stream used by HTTP/1 bodies providing an awaitable that is resolved when all body data has been read.
 * 
 * The entity stream will automatically send HTTP/1.1 100 Continue where expected.
 * 
 * @author Martin Schröder
 */
class EntityStream extends ReadableStreamDecorator
{
    protected $defer;
    
    protected $expectContinue;

    public function __construct(ReadableStream $stream, bool $cascadeClose = true, WritableStream & $expectContinue = null)
    {
        parent::__construct($stream);
        
        $this->cascadeClose = $cascadeClose;
        $this->expectContinue = & $expectContinue;
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

    /**
     * {@inheritdoc}
     */
    public function close(): Awaitable
    {
        $this->defer->resolve(null);
        
        return parent::close();
    }

    protected function readNextChunk(): \Generator
    {
        if ($this->expectContinue) {
            $expect = $this->expectContinue;
            $this->expectContinue = null;
            
            yield $expect->write("HTTP/1.1 100 Continue\r\n");
        }
        
        return yield $this->stream->read($this->bufferSize);
    }

    protected function processChunk(string $chunk): string
    {
        if ($chunk === '') {
            $this->defer->resolve(true);
        }
        
        return $chunk;
    }
}
