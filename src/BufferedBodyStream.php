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

namespace KoolKode\Async\Http;

use KoolKode\Async\Filesystem\FilesystemTempStream;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\ReadableStreamDecorator;

/**
 * Readable stream that manages temp file buffering of stream contents as they are being read.
 * 
 * @author Martin Schröder
 */
class BufferedBodyStream extends ReadableStreamDecorator
{
    /**
     * Source stream that needs buffering.
     * 
     * @var ReadableStream
     */
    protected $source;
    
    /**
     * Buffered HTTP body being used.
     * 
     * @var BufferedBody
     */
    protected $body;
    
    /**
     * Create a new buffered stream as needed by a buffered HTTP body.
     * 
     * @param FilesystemTempStream $temp Temp file stream being used as secondary buffer.
     * @param ReadableStream $source Source stream that needs buffering.
     * @param int $bufferSize In-memory buffer size (will be used as read size).
     * @param BufferedBody $body Buffered HTTP body being used.
     */
    public function __construct(FilesystemTempStream $temp, ReadableStream $source, int $bufferSize, BufferedBody $body)
    {
        parent::__construct($temp);
    
        $this->source = $source;
        $this->bufferSize = $bufferSize;
        $this->body = $body;
        $this->cascadeClose = false;
    }

    /**
     * Read next chunk from temp file.
     * 
     * Fall back to reading from buffered source stream if contents of the temp file have been read.
     */
    protected function readNextChunk(): \Generator
    {
        $chunk = yield $this->stream->readBuffer($this->bufferSize);
        
        if ($chunk === null) {
            $chunk = yield $this->source->readBuffer($this->bufferSize);
            
            if ($chunk === null) {
                $this->body->computeSize();
                $this->source->close();
            } else {
                $this->body->incrementOffset(yield $this->stream->write($chunk));
            }
            
            $chunk = yield $this->stream->readBuffer($this->bufferSize);
        }
        
        return $chunk;
    }

    /**
     * No processing needed, returns unmodified chunk.
     */
    protected function processChunk(string $chunk): string
    {
        return $chunk;
    }
}
