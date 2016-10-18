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
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableStreamDecorator;

/**
 * Duplex stream decorator that ensures persistent connections are not closed while they are in use.
 * 
 * @author Martin Schröder
 */
class PersistentStream extends ReadableStreamDecorator implements DuplexStream
{
    protected $refs = 0;
    
    protected $defer;

    public function __construct(DuplexStream $stream, int $refs = 0)
    {
        parent::__construct($stream);
        
        $this->refs = $refs;
        $this->defer = new class() extends Deferred {

            public function cancel(\Throwable $e): array
            {
                // This defer cannot be cancelled...
                return [];
            }
        };
    }

    public function reference()
    {
        $this->refs++;
    }
    
    /**
     * {@inheritdoc}
     */
    public function close(): Awaitable
    {
        $this->refs--;
        
        if ($this->refs < 1) {
            $this->defer->resolve(null);
            
            return parent::close();
        }
        
        return $this->defer;
    }
    
    /**
     * {@inheritdoc}
     */
    public function flush(): Awaitable
    {
        return $this->stream->flush();
    }
    
    /**
     * {@inheritdoc}
     */
    public function write(string $data): Awaitable
    {
        return $this->stream->write($data);
    }

    protected function processChunk(string $chunk): string
    {
        return $chunk;
    }
}
