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

    /**
     * Current read buffer.
     * 
     * @var string
     */
    protected $buffer = '';

    public function __construct(InputStreamInterface $stream)
    {
        $this->stream = $stream;
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
        
        if ($this->strean !== NULL) {
            try {
                $this->strean->close();
            } finally {
                $this->strean = NULL;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function eof(): \Generator
    {
        if ($this->ended) {
            return true;
        }
        
        if ($this->buffer !== '') {
            return false;
        }
        
        if ($this->remainder === 0) {
            if ($this->stream === NULL) {
                return true;
            }
            
            yield from $this->loadBuffer();
        }
        
        return $this->remainder === 0;
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length = 8192): \Generator
    {
        if ($this->stream === NULL) {
            throw new \RuntimeException('Cannot read from detached stream');
        }
        
        if ($length == 0) {
            return '';
        }
        
        if ($this->remainder === 0) {
            yield from $this->loadBuffer();
        }
        
        if ($this->ended) {
            return '';
        }
        
        $chunk = (string) substr($this->buffer, 0, min($length, $this->remainder));
        $length = strlen($chunk);
        
        $this->buffer = (string) substr($this->buffer, $length);
        $this->remainder -= $length;
        
        return $chunk;
    }

    /**
     * Load data of the next chunk into memory.
     */
    protected function loadBuffer(): \Generator
    {
        $this->remainder = 0;
        
        if ($this->ended) {
            return;
        }
        
        while (strlen($this->buffer) < 8192 && !yield from $this->stream->eof()) {
            $this->buffer .= yield from $this->stream->read(8192);
        }
        
        $m = NULL;
        
        if (preg_match("'^\r?\n?([a-fA-F0-9]+).*\r\n'", $this->buffer, $m)) {
            $this->remainder = hexdec($m[1]);
            $this->buffer = (string) substr($this->buffer, strlen($m[0]));
            
            if ($this->remainder === 0) {
                $this->buffer = '';
                $this->ended = true;
            } else {
                while (strlen($this->buffer) < $this->remainder && !yield from $this->stream->eof()) {
                    $this->buffer .= yield from $this->stream->read($this->remainder);
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
