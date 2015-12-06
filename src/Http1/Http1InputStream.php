<?php

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Stream\InputStreamInterface;

class Http1InputStream implements InputStreamInterface
{
    protected $stream;
    
    protected $dechunk;
    
    protected $zlib;
    
    protected $remainder = 0;
    
    protected $chunked = '';
    
    protected $buffer = '';
    
    public function __construct(InputStreamInterface $stream, bool $dechunk, $zlib = NULL)
    {
        $this->stream = $stream;
        $this->dechunk = $dechunk;
        $this->zlib = $zlib;
    }
    
    public function close()
    {
        $this->stream->close();
    }
    
    public function eof(): bool
    {
        return $this->buffer === '' && $this->remainder === 0 && $this->stream->eof();
    }
    
    public function read(int $length = 8192, bool $fillBuffer = false): \Generator
    {
        if ($fillBuffer) {}
        
//         while ($this->buffer === '') {
//             if($this->dechunk) {
//                 foreach ($this->decodeChunkedData() as $chunk) {
                    
//                 }
//             }
//         }
        
        if ($this->buffer !== '') {
            $chunk = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, strlen($chunk));
            
            return $chunk;
        }
    }
    
    /**
     * Decompress data as needed.
     *
     * @param string $data
     * @param \stdClass $decoder
     */
    protected function decodeData(string $data): \Generator
    {
        if ($data === '') {
            return;
        }
    
        if ($this->zlib !== NULL) {
            $data = inflate_add($this->zlib, $data, ZLIB_SYNC_FLUSH);
        }
    
        if (strlen($data) > 0) {
            yield $data;
        }
    }
    
    /**
     * Chunk decode and decompress data as needed.
     *
     * @param string $data
     * @param \stdClass $decoder
     */
    protected function decodeChunkedData(string $data): \Generator
    {
        if ($data === '') {
            return;
        }
    
        $this->chunked .= $data;
    
        while (strlen($this->chunked) > 0) {
            if ($this->remainder === 0) {
                $m = NULL;
    
                if (!preg_match("'^(?:\r\n)?([a-fA-f0-9]+)[^\n]*\r\n'", $this->chunked, $m)) {
                    break;
                }
    
                $this->remainder = (int) hexdec(ltrim($m[1], '0'));
    
                if ($this->remainder === 0) {
                    break;
                }
    
                $this->chunked = substr($this->chunked, strlen($m[0]));
            }
    
            $chunk = (string) substr($this->chunked, 0, min($this->remainder, strlen($this->chunked)));
            $this->chunked = (string) substr($this->chunked, strlen($chunk));
            $this->remainder -= strlen($chunk);
    
            if ($this->zlib === NULL) {
                yield $chunk;
            } else {
                $chunk = inflate_add($this->zlib, $chunk, ZLIB_SYNC_FLUSH);
    
                if (strlen($chunk) > 0) {
                    yield $chunk;
                }
            }
        }
    }
}
