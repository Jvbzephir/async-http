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
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\SocketStream;
use KoolKode\Async\SystemCall;
use KoolKode\K1\Http\DefaultHttpFactory;
use KoolKode\K1\Http\Http;
use KoolKode\K1\Http\HttpFactoryInterface;
use KoolKode\Stream\ResourceInputStream;
use Psr\Http\Message\RequestInterface;

/**
 * HTTP/1 client endpoint.
 * 
 * @author Martin Schröder
 */
class Http1Connector
{
    protected $httpFactory;
    
    public function __construct(HttpFactoryInterface $factory = NULL)
    {
        $this->httpFactory = $factory ?? new DefaultHttpFactory();
    }

    /**
     * Coroutine that sends an HTTP/1 request and returns the receved HTTP response.
     * 
     * @param RequestInterface $request
     * @return Generator
     */
    public function send(RequestInterface $request): \Generator
    {
        $uri = $request->getUri();
        $secure = $uri->getScheme() === 'https';
        
        $host = $uri->getHost();
        $port = $uri->getPort() ?? ($secure ? Http::PORT_SECURE : Http::PORT);
        
        $stream = yield from SocketStream::connect($host, $port);
        
        try {
            if ($secure) {
                yield from $stream->encrypt(STREAM_CRYPTO_METHOD_TLS_CLIENT);
            }
            
            $request = $this->prepareRequest($request);
            
            yield from $this->sendRequest($stream, $request);

            return yield from $this->processResponse($stream, $request);
        } finally {
            $stream->close();
        }
    }
    
    protected function prepareRequest(RequestInterface $request): RequestInterface
    {
        $request = $request->withHeader('Date', gmdate('D, d M Y H:i:s \G\M\T', time()));
        $request = $request->withHeader('Connection', 'close');
        
        $remove = [
            'Accept-Encoding',
            'Content-Encoding',
            'Content-Length',
            'Keep-Alive',
            'TE',
            'Transfer-Encoding'
        ];
        
        foreach ($remove as $header) {
            $request = $request->withoutHeader($header);
        }
        
        if (function_exists('inflate_init')) {
            $request = $request->withHeader('Accept-Encoding', 'gzip, deflate');
        }
        
        return $request;
    }
    
    /**
     * Assemble and stream request data to the remote endpoint.
     * 
     * @param SocketStream $stream
     * @param RequestInterface $request
     * 
     * @throws \RuntimeException
     */
    protected function sendRequest(SocketStream $stream, RequestInterface $request): \Generator
    {
        $body = $request->getBody();
        $size = $body->getSize();
        
        $in = $body->detach();
        if (!is_resource($in)) {
            throw new \RuntimeException('Cannot stream HTTP request body if detach() does not return a resource');
        }
        
        stream_set_blocking($in, 0);
        $in = new SocketStream($in);
        
        try {
            $chunk = yield from $in->read();
            $chunked = false;
            
            if ($size < 1 || ($size === NULL && $chunk !== '')) {
                $request = $request->withHeader('Content-Length', '0');
            } elseif ($size !== NULL) {
                $request = $request->withHeader('Content-Length', (string) $size);
            } else {
                $request = $request->withHeader('Transfer-Encoding', 'chunked');
                $chunked = true;
            }
            
            $message = sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());
            
            foreach ($request->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $message .= sprintf("%s: %s\n", $name, $value);
                }
            }
            
            yield from $stream->write($message . "\r\n");
            
            if ($chunked) {
                yield from $stream->write(sprintf('%x\r\n%s\r\n', strlen($chunk), $chunk));
                
                while (!$in->eof()) {
                    $chunk = yield from $in->read();
                    
                    yield from $stream->write(sprintf('%x\r\n%s\r\n', strlen($chunk), $chunk));
                }
                
                yield from $stream->write("0\r\n\r\n");
            } else {
                yield from $stream->write($chunk);
                
                while (!$in->eof()) {
                    yield from $stream->write(yield from $in->read());
                }
            }
        } finally {
            $in->close();
        }
    }
    
    /**
     * Read and parse raw HTTP response.
     * 
     * @param DuplexStreamInterface $stream
     * @param RequestInterface $request
     * 
     * @throws \RuntimeException
     */
    protected function processResponse(DuplexStreamInterface $stream, RequestInterface $request): \Generator
    {
        $stream = new BufferedDuplexStream($stream);
        $line = yield from $stream->readLine();
        
        $m = NULL;
        if (!preg_match("'^HTTP/(1\\.[0-1])\s+([0-9]{3})\s*(.*)$'i", $line, $m)) {
            throw new \RuntimeException('Response did not contain a valid HTTP status line');
        }
        
        $response = $this->httpFactory->createResponse();
        $response = $response->withProtocolVersion($m[1]);
        $response = $response->withStatus((int) $m[2], trim($m[3]));
        
        while (!$stream->eof()) {
            $line = yield from $stream->readLine();
            
            if ($line === '') {
                break;
            }
            
            $header = array_map('trim', explode(':', $line, 2));
            $response = $response->withAddedHeader($header[0], $header[1]);
        }
        
        $dechunk = false;
        $zlib = NULL;
        
        if ('chunked' === strtolower($response->getHeaderLine('Transfer-Encoding', ''))) {
            $dechunk = true;
        }
        
        switch ($response->getHeaderLine('Content-Encoding')) {
            case 'gzip':
                $zlib = inflate_init(ZLIB_ENCODING_GZIP);
                break;
            case 'deflate':
                $zlib = inflate_init(ZLIB_ENCODING_DEFLATE);
                break;
        }
        
        $remove = [
            'Connection',
            'Content-Encoding',
            'Content-Length',
            'Keep-Alive',
            'Trailer',
            'Transfer-Encoding'
        ];
        
        foreach ($remove as $header) {
            $response = $response->withoutHeader($header);
        }
        
        return $response->withBody(yield from $this->processResponseBody($stream, $dechunk, $zlib));
    }
    
    /**
     * Buffer response data to a temp stream.
     * 
     * @param DuplexStreamInterface $stream
     * @param bool $dechunk
     * @param unknown $zlib
     */
    protected function processResponseBody(DuplexStreamInterface $stream, bool $dechunk, $zlib = NULL): \Generator
    {
        $decoder = (object) [
            'zlib' => $zlib,
            'dechunk' => $dechunk,
            'remainder' => 0,
            'buffer' => ''
        ];
        
        $out = yield SystemCall::createTempStream();
        
        if ($dechunk) {
            while (!$stream->eof()) {
                foreach ($this->decodeChunkedData(yield from $stream->read(), $decoder) as $chunk) {
                    yield from $out->write($chunk);
                }
            }
        } else {
            while (!$stream->eof()) {
                foreach ($this->decodeData(yield from $stream->read(), $decoder) as $chunk) {
                    yield from $out->write($chunk);
                }
            }
        }
        
        if ($decoder->zlib !== NULL) {
            yield from $out->write(inflate_add($decoder->zlib, '', ZLIB_FINISH));
        }
        
        $out = $out->detach();
        stream_set_blocking($out, 1);
        
        rewind($out);
        
        return new ResourceInputStream($out);
    }
    
    /**
     * Decompress data as needed.
     *
     * @param string $data
     * @param \stdClass $decoder
     */
    protected function decodeData(string $data, \stdClass $decoder): \Generator
    {
        if ($data === '') {
            return;
        }
        
        if ($decoder->zlib !== NULL) {
            $data = inflate_add($decoder->zlib, $data, ZLIB_SYNC_FLUSH);
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
    protected function decodeChunkedData(string $data, \stdClass $decoder): \Generator
    {
        if ($data === '') {
            return;
        }
        
        $decoder->buffer .= $data;
        
        while (strlen($decoder->buffer) > 0) {
            if ($decoder->remainder === 0) {
                $m = NULL;
                
                if (!preg_match("'^(?:\r\n)?([a-fA-f0-9]+)[^\n]*\r\n'", $decoder->buffer, $m)) {
                    break;
                }
                
                $decoder->remainder = (int) hexdec(ltrim($m[1], '0'));
                
                if ($decoder->remainder === 0) {
                    break;
                }
                
                $decoder->buffer = substr($decoder->buffer, strlen($m[0]));
            }
            
            $chunk = (string) substr($decoder->buffer, 0, min($decoder->remainder, strlen($decoder->buffer)));
            $decoder->buffer = (string) substr($decoder->buffer, strlen($chunk));
            $decoder->remainder -= strlen($chunk);
            
            if ($decoder->zlib === NULL) {
                yield $chunk;
            } else {
                $chunk = inflate_add($decoder->zlib, $chunk, ZLIB_SYNC_FLUSH);
                
                if (strlen($chunk) > 0) {
                    yield $chunk;
                }
            }
        }
    }
}
