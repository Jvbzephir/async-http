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

use KoolKode\Async\Awaitable;
use KoolKode\Async\CopyBytes;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Http\StringBody;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableDeflateStream;
use KoolKode\Async\Stream\StreamClosedException;

class Driver
{
    protected $parser;
    
    protected $keepAliveSupported = false;
    
    protected $debug = false;
    
    public function __construct(RequestParser $parser = null)
    {
        $this->parser = $parser ?? new RequestParser();
    }
    
    public function setDebug(bool $debug)
    {
        $this->debug = $debug;
    }
    
    public function getProtocols(): array
    {
        return [
            'http/1.1'
        ];
    }
    
    public function handleConnection(DuplexStream $stream): Awaitable
    {
        return new Coroutine(function () use ($stream) {
            try {
                do {
                    $request = yield from $this->parser->parseRequest($stream);
                    $request->getBody()->setCascadeClose(false);
                    
                    if ($request->getProtocolVersion() == '1.1') {
                        if (!$request->hasHeader('Host')) {
                            throw new StatusException(Http::BAD_REQUEST, 'Missing HTTP Host header');
                        }
                        
                        if (\in_array('100-continue', $request->getHeaderTokens('Expect'), true)) {
                            $request->getBody()->setExpectContinue($stream);
                        }
                    }
                    
                    if (!$this->keepAliveSupported) {
                        $close = true;
                    } elseif ($request->getProtocolVersion() == '1.0') {
                        // HTTP/1.0 does not support keep alive.
                        $close = true;
                    } elseif (\in_array('close', $request->getHeaderTokens('Connection', ','), true)) {
                        // Close connection if client does not want to use keep alive.
                        $close = true;
                    } elseif (!$request->hasHeader('Content-Length') && 'chunked' !== \strtolower($request->getHeaderLine('Transfer-Encoding'))) {
                        // 
                        $close = true;
                    } else {
                        $close = false;
                    }
                    
                    $response = new HttpResponse(Http::OK, [], $request->getProtocolVersion());
                    $response = $response->withHeader('Server', 'KoolKode Async HTTP Server');
                    $response = $response->withBody(new \KoolKode\Async\Http\StringBody('Hello Test Client :)'));
                    
                    yield from $this->sendResponse($stream, $request, $response, $close);
                } while (!$close);
            } catch (StreamClosedException $e) {
                yield from $this->handleClosedConnection($e);
            } catch (\Throwable $e) {
                $response = new HttpResponse(Http::INTERNAL_SERVER_ERROR);
                
                if ($e instanceof StatusException) {
                    try {
                        $response = $response->withStatus($e->getCode(), $this->debug ? $e->getMessage() : '');
                    } catch (\Throwable $e) {}
                }
                
                yield from $this->sendErrorResponse($stream, $request, $response);
            } finally {
                $stream->close();
            }
        });
    }

    protected function handleClosedConnection(\Throwable $e): \Generator
    {
        // TODO: Client dropped connection, cleanup pending awaitables and log this event.
        yield 1;
    }

    protected function sendErrorResponse(DuplexStream $stream, HttpRequest $request, HttpResponse $response): \Generator
    {
        try {
            yield from $this->sendResponse($stream, $request, $response, true);
        } catch (\Throwable $e) {
            yield from $this->handleClosedConnection($e);
        }
    }

    protected function sendResponse(DuplexStream $stream, HttpRequest $request, HttpResponse $response, bool $close): \Generator
    {
        $response = $this->normalizeResponse($request, $response);
        
        $input = yield $request->getBody()->getReadableStream();
        
        // Discard remaining request body before sending response.
        while (null !== yield $input->read());
        
        $http11 = ($response->getProtocolVersion() == '1.1');
        $nobody = ($request->getMethod() === Http::HEAD || Http::isResponseWithoutBody($response->getStatusCode()));
        $body = $response->getBody();
        $size = yield $body->getSize();
        
        $reason = \trim($response->getReasonPhrase());
        
        if ($reason === '') {
            $reason = Http::getReason($response->getStatusCode());
        }
        
        $buffer = \sprintf("HTTP/%s %u%s\r\n", $response->getProtocolVersion(), $response->getStatusCode(), \rtrim(' ' . $reason));
        
        if ($close) {
            $buffer .= "Connection: close\r\n";
        }
        
        $compress = null;
        
        if (!$nobody && $size !== 0) {
            $buffer .= $this->enableCompression($request, $compress, $size);
        }
        
        $bodyStream = yield $body->getReadableStream();
        
        // HTTP/1.0 responses of unknown size are delimited by EOF / connection closed at the client's side.
        if (!$nobody && ($http11 || $size !== null)) {
            if ($size === null) {
                $buffer .= "Transfer-Encoding: chunked\r\n";
            } else {
                $buffer .= "Content-Length: $size\r\n";
            }
        }
        
        foreach ($response->getHeaders() as $name => $header) {
            $name = Http::normalizeHeaderName($name);
            
            foreach ($header as $value) {
                $buffer .= $name . ': ' . $value . "\r\n";
            }
        }
        
        yield $stream->write($buffer . "\r\n");
        yield $stream->flush();
        
        if ($compress !== null) {
            $bodyStream = new ReadableDeflateStream($bodyStream, $compress);
        }
        
        if ($nobody) {
            $bodyStream->close();
        } else {
            if ($size === null) {
                // Align each chunk with length and line breaks to fit into 4 KB payload.
                yield new CopyBytes($bodyStream, $stream, true, null, 4089, function (string $chunk) {
                    return \dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n";
                });
                
                yield $stream->write("0\r\n\r\n");
            } else {
                yield new CopyBytes($bodyStream, $stream, true, $size);
            }
            
            yield $stream->flush();
        }
    }

    protected function normalizeResponse(HttpRequest $request, HttpResponse $response): HttpResponse
    {
        static $remove = [
            'Connection',
            'Content-Encoding',
            'Content-Length',
            'Keep-Alive',
            'Trailer',
            'Transfer-Encoding'
        ];
        
        $response = $response->withProtocolVersion($request->getProtocolVersion());
        
        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }
        
        return $response->withHeader('Date', \gmdate(Http::DATE_RFC1123));
    }

    protected function enableCompression(HttpRequest $request, & $compress, & $size): string
    {
        static $available;
        
        if ($available === null) {
            $available = \function_exists('deflate_init');
        }
        
        if (!$available) {
            return '';
        }
        
        static $map = [
            'gzip' => \ZLIB_ENCODING_GZIP,
            'x-gzip' => \ZLIB_ENCODING_GZIP,
            'deflate' => \ZLIB_ENCODING_DEFLATE
        ];
        
        $accept = $request->getHeaderTokens('Accept-Encoding');
        
        foreach ($accept as $key) {
            if (isset($map[$key])) {
                $compress = $map[$key];
                $size = null;
                
                return \sprintf("Content-Encoding: %s\r\n", $key);
            }
        }
    }
}
