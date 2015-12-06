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

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Stream\BufferedDuplexStream;
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\SocketStream;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\HttpData;

/**
 * HTTP/1 client endpoint.
 * 
 * @author Martin Schröder
 */
class Http1Connector
{
    /**
     * Coroutine that sends an HTTP/1 request and returns the receved HTTP response.
     * 
     * @param HttpRequest $request
     * @return Generator
     */
    public function send(HttpRequest $request): \Generator
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
    
    protected function prepareRequest(HttpRequest $request): HttpRequest
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
//             $request = $request->withHeader('Accept-Encoding', 'gzip, deflate');
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
    protected function sendRequest(SocketStream $stream, HttpRequest $request): \Generator
    {
        $body = $request->getBody();
        
        $chunk = yield from $body->read();
        $chunked = false;
        
        if ($chunk === '') {
            $request = $request->withHeader('Content-Length', '0');
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
            
            while (!$body->eof()) {
                $chunk = yield from $body->read();
                
                if ($chunk !== '') {
                    yield from $stream->write(sprintf('%x\r\n%s\r\n', strlen($chunk), $chunk));
                }
            }
            
            yield from $stream->write("0\r\n\r\n");
        } else {
            yield from $stream->write($chunk);
            
            while (!$body->eof()) {
                yield from $stream->write(yield from $body->read());
            }
        }
    }
    
    /**
     * Read and parse raw HTTP response.
     * 
     * @param DuplexStreamInterface $stream
     * @param HttpRequest $request
     * 
     * @throws \RuntimeException
     */
    protected function processResponse(DuplexStreamInterface $stream, HttpRequest $request): \Generator
    {
        $stream = new BufferedDuplexStream($stream);
        $line = yield from $stream->readLine();
        
        $m = NULL;
        if (!preg_match("'^HTTP/(1\\.[0-1])\s+([0-9]{3})\s*(.*)$'i", $line, $m)) {
            throw new \RuntimeException('Response did not contain a valid HTTP status line');
        }
        
        $response = new HttpResponse();
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
        
        return $response->withBody($this->processResponseBody($stream, $dechunk, $zlib));
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
        // Avoid processing first chunk of data on init.
        yield;
        
        $decoder = (object) [
            'zlib' => $zlib,
            'dechunk' => $dechunk,
            'remainder' => 0,
            'buffer' => ''
        ];
        
        if ($dechunk) {
            while (!$stream->eof()) {
                foreach ($this->decodeChunkedData(yield from $stream->read(), $decoder) as $chunk) {
                    yield new HttpData($chunk);
                }
            }
        } else {
            while (!$stream->eof()) {
                foreach ($this->decodeData(yield from $stream->read(), $decoder) as $chunk) {
                    yield new HttpData($chunk);
                }
            }
        }
        
        if ($decoder->zlib !== NULL) {
            yield new HttpData(inflate_add($decoder->zlib, '', ZLIB_FINISH));
        }
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
