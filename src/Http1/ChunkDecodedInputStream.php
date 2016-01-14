<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Stream\BufferedDuplexStream;
use KoolKode\Async\Stream\InputStreamInterface;
use KoolKode\Async\Stream\SocketClosedException;

/**
 * Stream that transparently applies HTTP chunk decoding.
 * 
 * @author Martin Schröder
 */
class ChunkDecodedInputStream implements InputStreamInterface
{
    /**
     * Wrapped input stream that provides chunk-encoded data.
     * 
     * @var InputStreamInterface
     */
    protected $stream;

    /**
     * Number of remaining bytes t be read from the current chunk.
     * 
     * @var int
     */
    protected $remainder = 0;

    /**
     * End of chunk-encoded stream reached?
     * 
     * @var bool
     */
    protected $ended = false;
    
    /**
     * Cascade the close operation to the wrapped stream?
     * 
     * @var bool
     */
    protected $cascadeClose;

    /**
     * Buffered bytes of the current chunk.
     * 
     * @var string
     */
    protected $buffer = '';

    /**
     * Create a stream that will decode HTTP chunk-encoded data.
     * 
     * @param InputStreamInterface $stream Stream that provides chunk-encoded data.
     * @param string $buffer Buffer containing at least the header (first line) of the first chunk.
     * @param bool $cascadeClose Cascade the close operation to the wrapped stream?
     */
    public function __construct(InputStreamInterface $stream, string $buffer, bool $cascadeClose = true)
    {
        $this->stream = $stream;
        $this->cascadeClose = $cascadeClose;
        
        $m = NULL;
        
        if (!preg_match("'^([a-fA-F0-9]+)(?:;[^\r\n]*)?\r\n'", $buffer, $m)) {
            $this->ended = true;
            $this->buffer = '';
            
            throw new \RuntimeException('Buffer does not contain a valid chunk header');
        }
        
        if (strlen($m[1]) > 7) {
            throw new \RuntimeException('HTTP chunk size must not exceed 0xFFFFFFF bytes');
        }
        
        $this->remainder = hexdec($m[1]);
        $this->buffer = substr($buffer, strlen($m[0]));
        
        if ($this->remainder === 0) {
            $this->ended = true;
            $this->buffer = '';
        }
    }

    /**
     * Coroutine that creates a chunk-decoded input stream from the given stream.
     * 
     * @param InputStreamInterface $stream Stream that provides chunk-encoded data.
     * @param bool $cascadeClose Cascade the close operation to the wrapped stream?
     * @return ChunkDecodedInputStream
     */
    public static function open(InputStreamInterface $stream, bool $cascadeClose = true): \Generator
    {
        if (!$stream instanceof BufferedDuplexStream) {
            $stream = new BufferedDuplexStream($stream);
        }
        
        return new static($stream, (yield from $stream->readLine()) . "\r\n", $cascadeClose);
    }
    
    /**
     * Assemble debug data.
     * 
     * @return array
     */
    public function __debugInfo(): array
    {
        $info = get_object_vars($this);
        $info['buffer'] = sprintf('%u bytes buffered', strlen($info['buffer']));
        
        return $info;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->ended = true;
        $this->remainder = 0;
        $this->buffer = '';
        
        if ($this->stream !== NULL) {
            try {
                if ($this->cascadeClose) {
                    $this->stream->close();
                }
            } finally {
                $this->stream = NULL;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function eof(): bool
    {
        if ($this->ended || $this->remainder === 0) {
            return true;
        }
        
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length = 8192, float $timeout = 0): \Generator
    {
        if ($this->stream === NULL || $this->ended) {
            throw new SocketClosedException('Cannot read from closed stream');
        }
        
        if ($this->buffer === '') {
            $this->buffer = yield from $this->stream->read(min(8192, $this->remainder), $timeout);
        }
        
        $chunk = substr($this->buffer, 0, min($length, $this->remainder));
        $length = strlen($chunk);
        
        $this->buffer = substr($this->buffer, $length);
        $this->remainder -= $length;
        
        if ($this->remainder === 0 && !$this->ended) {
            yield from $this->readChunkHeader($timeout);
        }
        
        return $chunk;
    }

    /**
     * Read header of next chunk into buffer.
     * 
     * This method will likely start to read and buffer contents of the next chunk.
     * 
     * @param float $timeout Read timeout in seconds (0 indicates no timeout).
     */
    protected function readChunkHeader(float $timeout = 0): \Generator
    {
        while ((strlen($this->buffer) < 3 || false === strpos($this->buffer, "\n", 2)) && !$this->stream->eof()) {
            $this->buffer .= yield from $this->stream->read(8192, $timeout);
        }
        
        $m = NULL;
        
        if (!preg_match("'^\r\n([a-fA-F0-9]+)(?:;[^\r\n]*)?\r\n'", $this->buffer, $m)) {
            $this->ended = true;
            $this->remainder = 0;
            $this->buffer = '';
            
            throw new \RuntimeException('Invalid HTTP chunk header received');
        }
        
        if (strlen($m[1]) > 7) {
            throw new \RuntimeException('HTTP chunk size must not exceed 0xFFFFFFF bytes');
        }
        
        $this->remainder = hexdec($m[1]);
        $this->buffer = substr($this->buffer, strlen($m[0]));
        
        if ($this->remainder === 0) {
            $this->buffer = '';
            $this->ended = true;
        }
    }
}
