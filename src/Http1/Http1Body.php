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

namespace KoolKode\Async\Http\Http1;

use Interop\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpBody;
use KoolKode\Async\Http\HttpMessage;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableInflateStream;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Success;

/**
 * HTTP/1 message body decoder implementation.
 * 
 * Supports chunked-encoding, length-encoding, compression and expect-continue.
 * 
 * @author Martin Schröder
 */
class Http1Body implements HttpBody
{
    const COMPRESSION_GZIP = 'gzip';

    const COMPRESSION_DEFLATE = 'deflate';

    /**
     * HTTP protocol version (needed by expect-continue).
     * 
     * @var string
     */
    protected $protocolVersion;

    /**
     * Wrapped input stream that is being used to receive data from the remote peer.
     * 
     * @var DuplexStream
     */
    protected $socket;

    /**
     * Input stream that is being used to read decoded data.
     * 
     * @var ReadableStream
     */
    protected $stream;

    /**
     * Is incoming data chunk-encoded?
     * 
     * @var bool
     */
    protected $chunked = false;

    /**
     * Length of input data as specified by Content-Length.
     * 
     * A value of NULL indicates that the HTTP header was not set.
     * 
     * @var int
     */
    protected $length;

    /**
     * Compression method being used by the remote peer.
     * 
     * A value of NULL indicates thatincoming data is not compressed.
     * 
     * @var string
     */
    protected $compression;

    /**
     * Indicates that the remote peer expects a 100 Continue response before data will be sent.
     * 
     * @var bool
     */
    protected $expectContinue = false;

    /**
     * Cascade the close operation of the input stream to the socket being used to communicate with the remote peer.
     * 
     * @var bool
     */
    protected $cascadeClose = true;

    /**
     * Create a body that can decode contents received by the given socket.
     * 
     * @param DuplexStream $socket
     * @param string $protocolVersion
     */
    public function __construct(DuplexStream $socket, string $protocolVersion)
    {
        $this->protocolVersion = $protocolVersion;
        $this->socket = $socket;
    }

    /**
     * Ensure the underlying stream is closed in case of cascaded close.
     */
    public function __destruct()
    {
        if ($this->cascadeClose) {
            $this->socket->close();
        }
    }

    /**
     * Assemble HTTP body settings from the given HTTP message.
     * 
     * Exceptions thrown by this method use codes that can be sent as HTTP response status codes.
     * 
     * @param DuplexStream $socket
     * @param HttpMessage $message
     * @return Http1Body
     */
    public static function fromHeaders(DuplexStream $socket, HttpMessage $message): Http1Body
    {
        $body = new static($socket, $message->getProtocolVersion());
        
        if ($message->hasHeader('Transfer-Encoding')) {
            $encodings = \strtolower($message->getHeaderLine('Transfer-Encoding'));
            $encodings = \array_map('trim', \explode(',', $encodings));
            
            if (\in_array('chunked', $encodings)) {
                $body->setChunkEncoded(true);
            } elseif (!empty($encodings)) {
                throw new \RuntimeException('Unsupported transfer encoding detected', Http::NOT_IMPLEMENTED);
            }
        } elseif ($message->hasHeader('Content-Length')) {
            $len = $message->getHeaderLine('Content-Length');
            
            if (!\preg_match("'^[0-9]+$'", $len)) {
                throw new \RuntimeException(\sprintf('Invalid content length value specified: "%s"', $len), Http::BAD_REQUEST);
            }
            
            $body->setLength((int) $len);
        }
        
        if ($message instanceof HttpRequest) {
            if ($message->hasHeader('Expect') && $message->getProtocolVersion() == '1.1') {
                $expected = \array_map('strtolower', \array_map('trim', $message->getHeaderLine('Expect')));
                
                if (\in_array('100-continue', $expected)) {
                    $body->setExpectContinue(true);
                }
            }
        }
        
        if ($message->hasHeader('Content-Encoding')) {
            if (!$message instanceof HttpResponse) {
                throw new \RuntimeException('Compressed request bodies are not supported', Http::BAD_REQUEST);
            }
            
            $body->setCompression($message->getHeaderLine('Content-Encoding'));
        }
        
        return $body;
    }

    /**
     * Get available compression encoding names.
     * 
     * @return array
     */
    public static function getAvailableCompressionEncodings(): array
    {
        static $compression;
        
        if ($compression === NULL) {
            $compression = \function_exists('inflate_init');
        }
        
        if (!$compression) {
            return [];
        }
        
        return [
            self::COMPRESSION_GZIP,
            self::COMPRESSION_DEFLATE
        ];
    }

    /**
     * Cascade the close operation to the underlying data stream being used to communicate with the remote peer?
     * 
     * @param bool $cascadeClose
     */
    public function setCascadeClose(bool $cascadeClose)
    {
        $this->cascadeClose = $cascadeClose;
    }

    /**
     * Apply chunk-decoding to the message body?
     * 
     * @param bool $chunked
     */
    public function setChunkEncoded(bool $chunked)
    {
        $this->chunked = $chunked;
    }

    /**
     * Apply length encoding to the message body.
     * 
     * @param int $length
     * 
     * @throws \InvalidArgumentException When a negative length is given.
     */
    public function setLength(int $length)
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Content length must not be negative', Http::BAD_REQUEST);
        }
        
        $this->length = $length;
    }

    /**
     * Set encoding being used to decompress body data.
     * 
     * @param string $encoding
     * 
     * @throws \InvalidArgumentException
     */
    public function setCompression(string $encoding)
    {
        $encoding = \strtolower($encoding);
        
        if (!\in_array($encoding, self::getAvailableCompressionEncodings(), true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported compression encoding: "%s"', $encoding), Http::NOT_IMPLEMENTED);
        }
        
        $this->compression = $encoding;
    }

    /**
     * Send 100 Continue response line before reading data from remote peer?
     * 
     * @param bool $expectContinue
     */
    public function setExpectContinue(bool $expectContinue)
    {
        $this->expectContinue = $expectContinue;
    }

    /**
     * {@inheritdoc}
     */
    public function isCached(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function prepareMessage(HttpMessage $message): HttpMessage
    {
        return $message;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize(): Awaitable
    {
        return new Success($this->chunked ? NULL : $this->length);
    }

    /**
     * {@inheritdoc}
     */
    public function getReadableStream(): Awaitable
    {
        return new Coroutine(function () {
            if ($this->stream === NULL) {
                $this->stream = yield from $this->createInputStream();
            }
            
            return $this->stream;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(): Awaitable
    {
        return new Coroutine(function () {
            if ($this->stream === NULL) {
                $this->stream = yield from $this->createInputStream();
            }
            
            return yield new ReadContents($this->stream);
        });
    }

    /**
     * Create the input stream being used to read decoded body data from the remote peer.
     * 
     * @return ReadableStream
     */
    protected function createInputStream(): \Generator
    {
        if ($this->expectContinue) {
            yield $this->socket->write(\sprintf("HTTP/%s 100 Continue\r\n", $this->protocolVersion));
        }
        
        if ($this->chunked) {
            $stream = new ChunkDecodedStream($this->socket, $this->cascadeClose);
        } elseif ($this->length > 0) {
            $stream = new LimitStream($this->socket, $this->length, $this->cascadeClose);
        } else {
            if ($this->cascadeClose) {
                $this->socket->close();
            }
            
            return new ReadableMemoryStream();
        }
        
        switch ($this->compression) {
            case self::COMPRESSION_GZIP:
                return new ReadableInflateStream($stream, \ZLIB_ENCODING_GZIP);
            case self::COMPRESSION_DEFLATE:
                return new ReadableInflateStream($stream, \ZLIB_ENCODING_DEFLATE);
        }
        
        return $stream;
    }
}
