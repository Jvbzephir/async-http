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
use KoolKode\Async\Stream\StreamException;

/**
 * Stream that transparently applies HTTP chunk decoding.
 * 
 * @author Martin Schröder
 */
class ChunkDecodedStream extends ReadableStreamDecorator
{
    /**
     * Number of remaining bytes to be read from the current chunk.
     * 
     * @var int
     */
    protected $remainder;

    /**
     * Create a stream that will decode HTTP chunk-encoded data.
     * 
     * @param ReadableStream $stream Stream that provides chunk-encoded data.
     * @param bool $cascadeClose Cascade the close operation to the wrapped stream?
     */
    public function __construct(ReadableStream $stream, bool $cascadeClose = true)
    {
        parent::__construct($stream);
        
        $this->cascadeClose = $cascadeClose;
    }

    /**
     * Read header of next chunk into buffer.
     * 
     * This method will likely start to read and buffer contents of the next chunk.
     */
    protected function readNextChunk(): \Generator
    {
        if ($this->remainder === 0) {
            if ("\r\n" !== yield $this->stream->readBuffer(2)) {
                throw new StreamException('Missing CRLF after chunk');
            }
        }
        
        if (empty($this->remainder)) {
            if (null === ($header = yield $this->stream->readLine())) {
                return;
            }
            
            $header = \trim(\preg_replace("';.*$'", '', $header));
            
            if (!\ctype_xdigit($header) || \strlen($header) > 7) {
                throw new StreamException(\sprintf('Invalid HTTP chunk length received: "%s"', $header));
            }
            
            $this->remainder = \hexdec($header);
            
            if ($this->remainder === 0) {
                return;
            }
        }
        
        return yield $this->stream->read(\min($this->bufferSize, $this->remainder));
    }

    protected function processChunk(string $chunk): string
    {
        $this->remainder -= \strlen($chunk);
        
        return $chunk;
    }
}
