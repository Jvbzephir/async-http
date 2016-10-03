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

use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\ReadableStreamDecorator;

/**
 * Input stream with pre-defined maximum number of bytes that can be read.
 * 
 * @author Martin Schröder
 */
class LimitStream extends ReadableStreamDecorator
{
    /**
     * Maximum number of bytes to be read.
     * 
     * @var int
     */
    protected $limit;

    /**
     * Current read offset.
     * 
     * @var int
     */
    protected $offset = 0;

    /**
     * Create a length-limited input stream.
     * 
     * @param ReadableStream $stream Wrapped input stream.
     * @param int $limit Maximum number of bytes to be read from the stream.
     * @param bool $cascadeClose Cascade call to close to wrapped stream?
     */
    public function __construct(ReadableStream $stream, int $limit, bool $cascadeClose = true)
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit must not be negative');
        }
        
        parent::__construct($stream);
        
        $this->limit = $limit;
        $this->cascadeClose = $cascadeClose;
    }

    protected function readNextChunk(): \Generator
    {
        $len = $this->limit - $this->offset;
        
        if ($len < 1) {
            return;
        }
        
        return yield $this->stream->read(\min($this->bufferSize, $len));
    }

    protected function processChunk(string $chunk): string
    {
        $this->offset += \strlen($chunk);
        
        return $chunk;
    }
}
