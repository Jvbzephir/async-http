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

/**
 * Decompresses contents of a binary WebSocket message during reads.
 * 
 * @author Martin Schröder
 */
class DecompressionStream extends ReadableStreamDecorator
{
    /**
     * Decompression context being used to inflate message contents.
     * 
     * @var resource
     */
    protected $context;

    /**
     * Zlib flush mode to be used for the final flush opertaion.
     * 
     * @var int
     */
    protected $flushMode;
    
    /**
     * Create a new WebSocket decompression stream.
     * 
     * @param ReadableStream $stream Underlying stream that provides compressed message data.
     * @param resource $context Decompression context being used to inflate message contents.
     * @param int $flushMode Zlib flush mode to be used for the final flush opertaion.
     */
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

    /**
     * Decompress the given chunk of data.
     */
    protected function processChunk(string $chunk): string
    {
        if ($chunk === '') {
            return \inflate_add($this->context, "\x00\x00\xFF\xFF", $this->flushMode);
        }
        
        return \inflate_add($this->context, $chunk, \ZLIB_SYNC_FLUSH);
    }
}
