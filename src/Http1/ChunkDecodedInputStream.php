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

use KoolKode\Async\Stream\InputStreamInterface;

/**
 * Stream that transparently applies HTTP chunk decoding.
 * 
 * @author Martin Schröder
 */
class ChunkDecodedInputStream implements InputStreamInterface
{
    protected $stream;

    /**
     * Remaining bytes within the current chunk.
     * 
     * @var int
     */
    protected $remainder = 0;

    /**
     * End of data reached?
     * 
     * @var bool
     */
    protected $ended = false;
    
    protected $cascadeClose;

    /**
     * Current read buffer.
     * 
     * @var string
     */
    protected $buffer = '';

    public function __construct(InputStreamInterface $stream, string $buffer, bool $cascadeClose = true)
    {
        $this->stream = $stream;
        $this->buffer = $buffer;
        $this->cascadeClose = $cascadeClose;
        
        $m = NULL;
        
        if (preg_match("'^([a-fA-F0-9]+).*\r\n'", $this->buffer, $m)) {
            $this->remainder = hexdec($m[1]);
            $this->buffer = (string) substr($this->buffer, strlen($m[0]));
        
            if ($this->remainder === 0) {
                $this->buffer = '';
                $this->ended = true;
            }
        } else {
            $this->buffer = '';
            $this->ended = true;
        }
    }

    /**
     * Assemble debug data.
     * 
     * @return array
     */
    public function __debugInfo()
    {
        $info = get_object_vars($this);
        $info['buffer'] = sprintf('%u bytes buffered', strlen($info['buffer']));
        
        return $info;
    }

    public function close()
    {
        $this->buffer = '';
        $this->ended = true;
        $this->remainder = 0;
        
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
        
        return $this->buffer === '';
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length = 8192, float $timeout = 0): \Generator
    {
        if ($this->stream === NULL) {
            throw new \RuntimeException('Cannot read from detached stream');
        }
        
        if ($length == 0) {
            return '';
        }
        
        while (strlen($this->buffer) < $this->remainder) {
            $this->buffer .= yield from $this->stream->read($this->remainder - strlen($this->buffer), $timeout);
        }
        
        if ($this->ended) {
            return '';
        }
        
        $chunk = (string) substr($this->buffer, 0, min($length, $this->remainder));
        $length = strlen($chunk);
        
        $this->buffer = (string) substr($this->buffer, $length);
        $this->remainder -= $length;
        
        if ($this->remainder === 0) {
            yield from $this->loadBuffer($timeout);
        }
        
        return $chunk;
    }

    /**
     * Load data of the next chunk into memory.
     */
    protected function loadBuffer(float $timeout = 0): \Generator
    {
        $this->remainder = 0;
        
        if ($this->ended) {
            return;
        }
        
        while (strlen($this->buffer) < 8192 && !$this->stream->eof()) {
            $this->buffer .= yield from $this->stream->read(8192, $timeout);
        }
        
        $m = NULL;
        
        if (preg_match("'^\r?\n?([a-fA-F0-9]+).*\r\n'", $this->buffer, $m)) {
            $this->remainder = hexdec($m[1]);
            $this->buffer = (string) substr($this->buffer, strlen($m[0]));
            
            if ($this->remainder === 0) {
                $this->buffer = '';
                $this->ended = true;
            } else {
                while (strlen($this->buffer) < $this->remainder && !$this->stream->eof()) {
                    $this->buffer .= yield from $this->stream->read($this->remainder, $timeout);
                }
                
                if (strlen($this->buffer) < $this->remainder) {
                    $this->buffer = '';
                    $this->ended = true;
                }
            }
        } else {
            $this->buffer = '';
            $this->ended = true;
        }
    }
}
