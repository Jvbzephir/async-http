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

use KoolKode\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpBody;
use KoolKode\Async\Http\HttpMessage;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Stream\ReadableInflateStream;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\WritableStream;
use KoolKode\Async\Success;

/**
 * HTTP/1 message body decoder implementation.
 * 
 * Supports chunked-encoding, length-encoding, compression and expect-continue.
 * 
 * @author Martin Schröder
 */
class Body implements HttpBody
{
    const COMPRESSION_GZIP = 'gzip';

    const COMPRESSION_DEFLATE = 'deflate';

    /**
     * Wrapped input stream that is being used to receive data from the remote peer.
     * 
     * @var ReadableStream
     */
    protected $stream;

    /**
     * Input stream that is being used to read decoded data.
     * 
     * @var ReadableStream
     */
    protected $decodedStream;

    /**
     * Is incoming data chunk-encoded?
     * 
     * @var bool
     */
    protected $chunked = false;

    /**
     * Length of input data as specified by Content-Length.
     * 
     * A value of null indicates that the HTTP header was not set.
     * 
     * @var int
     */
    protected $length;

    /**
     * Compression method being used by the remote peer.
     * 
     * A value of null indicates thatincoming data is not compressed.
     * 
     * @var string
     */
    protected $compression;

    /**
     * Indicates that the remote peer expects a 100 Continue response before data will be sent.
     * 
     * The 100 continue line will be sent to the given stream as needed.
     * 
     * @var WritableStream
     */
    protected $expectContinue;

    /**
     * Cascade the close operation of the input stream to the socket being used to communicate with the remote peer.
     * 
     * @var bool
     */
    protected $cascadeClose = true;
    
    /**
     * Can the length of the body be determined using stream EOF?
     * 
     * @var bool
     */
    protected $closeSupported;

    /**
     * Create a body that can decode contents received by the given socket.
     * 
     * @param ReadableStream $stream
     * @param bool $closeSupported Can the length of the body be determined using stream EOF?
     */
    public function __construct(ReadableStream $stream, bool $closeSupported = false)
    {
        $this->stream = $stream;
        $this->closeSupported = $closeSupported;
    }
    
    /**
     * Close underlying stream when cascaded close is requested and the body has not been accessed.
     */
    public function __destruct()
    {
        if ($this->cascadeClose && $this->decodedStream === null) {
            $this->stream->close();
        }
    }

    /**
     * Assemble HTTP body settings from the given HTTP message.
     * 
     * Exceptions thrown by this method use codes that can be sent as HTTP response status codes.
     * 
     * @param ReadableStream $stream
     * @param HttpMessage $message
     * @return Body
     */
    public static function fromMessage(ReadableStream $stream, HttpMessage $message): Body
    {
        $close = false;
        
        if ($message instanceof HttpResponse && \in_array('close', $message->getHeaderTokens('Connection'))) {
            $close = true;
        }
        
        $body = new static($stream, $close);
        
        if ($message->hasHeader('Transfer-Encoding')) {
            $encodings = \strtolower($message->getHeaderLine('Transfer-Encoding'));
            $encodings = \array_map('trim', \explode(',', $encodings));
            
            if (\in_array('chunked', $encodings)) {
                $body->setChunkEncoded(true);
            } elseif (!empty($encodings)) {
                throw new StatusException(Http::NOT_IMPLEMENTED, 'Unsupported transfer encoding detected');
            }
        } elseif ($message->hasHeader('Content-Length')) {
            $len = $message->getHeaderLine('Content-Length');
            
            if (!\preg_match("'^[0-9]+$'", $len)) {
                throw new StatusException(Http::BAD_REQUEST, \sprintf('Invalid content length value specified: "%s"', $len));
            }
            
            $body->setLength((int) $len);
        }
        
        if ($message->hasHeader('Content-Encoding')) {
            if (!$message instanceof HttpResponse) {
                throw new StatusException(Http::BAD_REQUEST, 'Compressed request bodies are not supported');
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
        
        if ($compression === null) {
            $compression = \function_exists('inflate_init');
        }
        
        return $compression ? [
            self::COMPRESSION_GZIP,
            self::COMPRESSION_DEFLATE
        ] : [];
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
     * @param WritableStream $stream
     */
    public function setExpectContinue(WritableStream $stream = null)
    {
        $this->expectContinue = $stream;
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
        return new Success($this->chunked ? null : $this->length);
    }

    /**
     * {@inheritdoc}
     */
    public function getReadableStream(): Awaitable
    {
        return new Coroutine(function () {
            if ($this->decodedStream === null) {
                $this->decodedStream = $this->createInputStream();
            }
            
            return $this->decodedStream;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(): Awaitable
    {
        return new Coroutine(function () {
            if ($this->decodedStream === null) {
                $this->decodedStream = $this->createInputStream();
            }
            
            return yield new ReadContents($this->decodedStream);
        });
    }

    /**
     * Create the input stream being used to read decoded body data from the remote peer.
     */
    protected function createInputStream(): EntityStream
    {
        if ($this->chunked) {
            $stream = new ChunkDecodedStream($this->stream);
        } elseif ($this->length > 0) {
            $stream = new LimitStream($this->stream, $this->length);
        } elseif ($this->closeSupported) {
            $stream = $this->stream;
        } else {
            if ($this->cascadeClose) {
                $this->stream->close();
            }
            
            return new EntityStream(new ReadableMemoryStream(), true, $this->expectContinue);
        }
        
        if ($this->compression) {
            switch ($this->compression) {
                case self::COMPRESSION_GZIP:
                    $stream = new ReadableInflateStream($stream, \ZLIB_ENCODING_GZIP);
                    break;
                case self::COMPRESSION_DEFLATE:
                    $stream = new ReadableInflateStream($stream, \ZLIB_ENCODING_DEFLATE);
                    break;
            }
        }
        
        return new EntityStream($stream, $this->cascadeClose, $this->expectContinue);
    }
}
