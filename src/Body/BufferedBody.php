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

namespace KoolKode\Async\Http\Body;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Success;
use KoolKode\Async\Filesystem\Filesystem;
use KoolKode\Async\Filesystem\FilesystemProxy;
use KoolKode\Async\Filesystem\FilesystemTempStream;
use KoolKode\Async\Http\HttpBody;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\ReadableStream;

/**
 * Provides transparent buffering of stream-based request bodies.
 * 
 * @author Martin Schröder
 */
class BufferedBody implements HttpBody
{
    /**
     * Wrapped reabale stream to be buffered.
     * 
     * @var ReadableStream
     */
    protected $stream;
    
    /**
     * In-memory buffering size threshold.
     * 
     * @var int
     */
    protected $bufferSize;
    
    /**
     * In-memory buffered data.
     * 
     * @var string
     */
    protected $buffer = '';
    
    /**
     * Size of the buffered body (will be null until all data has been buffered).
     * 
     * @var int
     */
    protected $size;
    
    /**
     * Number of bytes read from source stream.
     * 
     * @var int
     */
    protected $offset = 0;
    
    /**
     * Temp file being used to buffer data that does not fit into the in-memory buffer.
     * 
     * The file will be created as needed, this field is null until it is needed.
     * 
     * @var FilesystemTempStream
     */
    protected $temp;
    
    protected $filesystem;
    
    /**
     * Create a new buffered body from the given readable stream.
     * 
     * @param ReadableStream $stream
     * @param int $bufferSize
     */
    public function __construct(ReadableStream $stream, int $bufferSize = 65535)
    {
        $this->stream = $stream;
        $this->bufferSize = $bufferSize;
        
        $this->filesystem = new FilesystemProxy();
    }
    
    /**
     * Ensure the temp file is removed when the body is not needed anymore.
     */
    public function __destruct()
    {
        if ($this->temp) {
            $this->temp->close();
        }
    }
    
    public function setFilesystem(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }
    
    /**
     * {@inheritdoc}
     */
    public function isCached(): bool
    {
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getSize(): Awaitable
    {
        if ($this->size === null && !$this->temp) {
            return new Coroutine(function () {
                $buffer = yield $this->stream->readBuffer($this->bufferSize);
                $len = \strlen($buffer ?? '');
                
                if ($len < $this->bufferSize) {
                    $this->buffer = $buffer;
                    
                    return $this->size = $len;
                }
                
                $this->temp = yield $this->filesystem->tempStream();
                $this->offset += yield $this->temp->write($buffer);
            });
        }
        
        return new Success($this->size);
    }
    
    /**
     * Increment source stream read offset by the given number of bytes.
     */
    public function incrementOffset(int $delta)
    {
        $this->offset += $delta;
    }

    /**
     * Compute size based on current offset.
     * 
     * Must not be called before all bytes from the source stream have been read.
     */
    public function computeSize()
    {
        $this->size = $this->offset;
    }

    /**
     * {@inheritdoc}
     */
    public function getReadableStream(): Awaitable
    {
        if ($this->temp) {
            $this->temp->rewind();
            
            return new Success(new BufferedBodyStream($this->temp, $this->stream, $this->bufferSize, $this));
        }
        
        if ($this->size !== null) {
            return new Success(new ReadableMemoryStream($this->buffer));
        }
        
        return new Coroutine(function () {
            $buffer = yield $this->stream->readBuffer($this->bufferSize);
            $len = \strlen($buffer);
            
            if ($len < $this->bufferSize) {
                $this->buffer = $buffer;
                $this->size = $len;
                
                return new ReadableMemoryStream($buffer);
            }
            
            $this->temp = yield $this->filesystem->tempStream();
            $this->offset += yield $this->temp->write($buffer);
            
            return new BufferedBodyStream($this->temp, $this->stream, $this->bufferSize, $this);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(): Awaitable
    {
        return new Coroutine(function () {
            return yield new ReadContents(yield $this->getReadableStream());
        });
    }
    
    /**
     * {@inheritdoc}
     */
    public function discard(): Awaitable
    {
        return new Coroutine(function () {
            $stream = yield $this->getReadableStream();
            $len = $this->offset;
            
            try {
                while (null !== yield $stream->read());
            } finally {
                $stream->close();
            }
            
            return $this->size - $len;
        });
    }
}
