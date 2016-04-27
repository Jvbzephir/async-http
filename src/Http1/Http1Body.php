<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpBodyInterface;
use KoolKode\Async\Http\HttpMessage;
use KoolKode\Async\Stream\BufferedInputStreamInterface;
use KoolKode\Async\Stream\Stream;
use KoolKode\Async\Stream\StringInputStream;

class Http1Body implements HttpBodyInterface
{
    const COMPRESSION_GZIP = 'gzip';
    
    const COMPRESSION_DEFLATE = 'deflate';
    
    protected $protocolVersion;
    
    protected $socket;
    
    protected $stream;
    
    protected $chunked = false;
    
    protected $length;
    
    protected $compression;
    
    protected $expectContinue = false;
    
    protected $cascadeClose = true;
    
    protected static $compressionSupported;
    
    public function __construct(BufferedInputStreamInterface $socket, string $protocolVersion)
    {
        $this->protocolVersion = $protocolVersion;
        $this->socket = $socket;
    }
    
    public function __destruct()
    {
        if ($this->cascadeClose) {
            $this->socket->close();
        }
    }
    
    public static function fromHeaders(BufferedInputStreamInterface $socket, HttpMessage $message): Http1Body
    {
        $body = new static($socket, $message->getProtocolVersion());
        
        if ($message->hasHeader('Transfer-Encoding')) {
            $encodings = strtolower($message->getHeaderLine('Transfer-Encoding'));
            $encodings = array_map('trim', explode(',', $encodings));
            
            if (in_array('chunked', $encodings)) {
                $body->setChunkEncoded(true);
            } elseif (!empty($encodings)) {
                throw new \RuntimeException('Unsupported transfer encoding detected', Http::CODE_NOT_IMPLEMENTED);
            }
        } elseif ($message->hasHeader('Content-Length')) {
            $len = $message->getHeaderLine('Content-Length');
            
            if (!preg_match("'^[0-9]+$'", $len)) {
                throw new \RuntimeException(sprintf('Invalid content length value specified: "%s"', $len), Http::CODE_BAD_REQUEST);
            }
            
            $body->setLength((int) $len);
        }
        
        if ($message->hasHeader('Content-Encoding')) {
            $body->setCompression($message->getHeaderLine('Content-Encoding'));
        }
        
        if ($message->hasHeader('Expect') && $message->getProtocolVersion() == '1.1') {
            $expected = array_map('strtolower', array_map('trim', $message->getHeaderLine('Expect')));
            
            if (in_array('100-continue', $expected)) {
                $body->setExpectContinue(true);
            }
        }
        
        return $body;
    }
    
    public static function isCompressionSupported(): bool
    {
        if (self::$compressionSupported === NULL) {
            self::$compressionSupported = function_exists('inflate_init');
        }
        
        return self::$compressionSupported;
    }
    
    public function setCascadeClose(bool $cascadeClose)
    {
        $this->cascadeClose = $cascadeClose;
    }
    
    public function setChunkEncoded(bool $chunked)
    {
        $this->chunked = $chunked;
    }
    
    public function setLength(int $length)
    {
        if ($length < 0) {
            throw new \RuntimeException('Content length must not be negative', Http::CODE_BAD_REQUEST);
        }
        
        $this->length = $length;
    }
    
    public function setCompression(string $encoding)
    {
        if (self::isCompressionSupported()) {
            throw new \RuntimeException('Compression is not supported (zlib is required)', Http::CODE_NOT_IMPLEMENTED);
        }
        
        switch ($encoding) {
            case self::COMPRESSION_DEFLATE:
            case self::COMPRESSION_GZIP:
                $this->compression = $encoding;
                break;
            default:
                throw new \InvalidArgumentException(sprintf('Unsupported compression encoding: "%s"', $encoding), Http::CODE_NOT_IMPLEMENTED);
        }
    }
    
    public function setExpectContinue(bool $expectContinue)
    {
        $this->expectContinue = $expectContinue;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getSize(): \Generator
    {
        yield NULL;
        
        return $this->chunked ? NULL : $this->length;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getInputStream(): \Generator
    {
        if ($this->stream === NULL) {
            $this->stream = yield from $this->createInputStream();
        }
        
        return $this->stream;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getContents(): \Generator
    {
        if ($this->stream === NULL) {
            $this->stream = yield from $this->createInputStream();
        }
        
        return yield from Stream::readContents($this->stream);
    }
    
    protected function createInputStream(): \Generator
    {
        if ($this->expectContinue) {
            yield from $this->socket->write(sprintf("HTTP/%s 100 Continue\r\n", $this->protocolVersion));
        }
        
        if ($this->chunked) {
            $stream = yield from ChunkDecodedInputStream::open($this->socket, $this->cascadeClose);
        } elseif ($this->length > 0) {
            $stream = new LimitInputStream($this->socket, $this->length, $this->cascadeClose);
        } else {
            if ($this->cascadeClose) {
                $this->socket->close();
            }
            
            return new StringInputStream();
        }
        
        switch ($this->compression) {
            case self::COMPRESSION_GZIP:
                $stream = yield from InflateInputStream::open($stream, InflateInputStream::GZIP);
                break;
            case self::COMPRESSION_DEFLATE:
                $stream = yield from InflateInputStream::open($stream, InflateInputStream::DEFLATE);
                break;
        }
        
        return $stream;
    }
}
