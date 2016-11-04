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

namespace KoolKode\Async\Http\WebSocket;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\ReadableStreamDecorator;

class DecompressionStream extends ReadableStreamDecorator
{
    protected $context;
    
    protected $flushMode;
    
    public function __construct(ReadableStream $stream, $context, int $flushMode)
    {
        parent::__construct($stream);
        
        $this->context = $context;
        $this->flushMode = $flushMode;
    }
    
    /**
     * {@inheritdoc}
     */
    public function close(): Awaitable
    {
        $this->context = null;
        
        return parent::close();
    }

    protected function processChunk(string $chunk): string
    {
        if ($chunk === '') {
            return \inflate_add($this->context, "\x00\x00\xFF\xFF", $this->flushMode);
        }
        
        return \inflate_add($this->context, $chunk, \ZLIB_SYNC_FLUSH);
    }
}
