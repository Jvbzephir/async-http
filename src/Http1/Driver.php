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
use KoolKode\Async\Timeout;
use KoolKode\Async\Util\Executor;

class Driver
{
    protected $parser;
    
    protected $keepAliveSupported = true;
    
    protected $debug = false;
    
    public function __construct(RequestParser $parser = null)
    {
        $this->parser = $parser ?? new RequestParser();
    }
    
    public function setKeepAliveSupported(bool $keepAlive)
    {
        $this->keepAliveSupported = $keepAlive;
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
            $executor = new Executor();
            $jobs = new \SplQueue();
            
            try {
                do {
                    try {
                        $request = yield new Timeout(30, new Coroutine($this->parser->parseRequest($stream)));
                        $request->getBody()->setCascadeClose(false);
                        
                        if ($request->getProtocolVersion() == '1.1') {
                            if (!$request->hasHeader('Host')) {
                                throw new StatusException(Http::BAD_REQUEST, 'Missing HTTP Host header');
                            }
                            
                            if (\in_array('100-continue', $request->getHeaderTokens('Expect'), true)) {
                                $request->getBody()->setExpectContinue($stream);
                            }
                        }
                        
                        $close = $this->shouldConnectionBeClosed($request);
                        
                        $request = $request->withoutHeader('Content-Length');
                        $request = $request->withoutHeader('Transfer-Encoding');
                        
                        $jobs->enqueue($executor->execute(function () use ($stream, $request, $close) {
                            yield from $this->processRequest($stream, $request, $close);
                        }));
                        
                        // Wait until HTTP request body stream is closed.
                        yield ((yield $request->getBody()->getReadableStream())->getAwaitable());
                    } catch (StreamClosedException $e) {
                        break;
                    } catch (\Throwable $e) {
                        yield from $this->sendErrorResponse($stream, $request, $e);
                        
                        break;
                    }
                } while (!$close);
                
                while (!$jobs->isEmpty()) {
                    yield $jobs->dequeue();
                }
            } finally {
                while (!$jobs->isEmpty()) {
                    $jobs->dequeue()->cancel(new StreamClosedException('HTTP connection closed'));
                }
                
                $stream->close();
            }
        });
    }
    
    protected function shouldConnectionBeClosed(HttpRequest $request): bool
    {
        if (!$this->keepAliveSupported) {
            return true;
        }
        
        $conn = $request->getHeaderTokens('Connection');
        
        // HTTP/1.0 must explicitly specify keep-alive to use persistent connections.
        if ($request->getProtocolVersion() == '1.0' && !\in_array('keep-alive', $conn, true)) {
            return true;
        }
        
        // Close connection if client does not want to use keep-alive.
        if (\in_array('close', $conn, true)) {
            return true;
        }
        
        // Eighter content length or chunked encoding required to read request body.
        if (!$request->hasHeader('Content-Length') && 'chunked' !== \strtolower($request->getHeaderLine('Transfer-Encoding'))) {
            return true;
        }
        
        return false;
    }
    
    protected function processRequest(DuplexStream $stream, HttpRequest $request, bool $close): \Generator
    {
        try {
            $response = new HttpResponse(Http::OK, [], $request->getProtocolVersion());
            $response = $response->withHeader('Server', 'KoolKode HTTP Server');
            
            if ($request->getMethod() == Http::POST) {
                $response = $response->withHeader('Content-Type', $request->getHeaderLine('Content-Type'));
                $response = $response->withBody(new StringBody(yield $request->getBody()->getContents()));
            } else {
                $response = $response->withBody(new StringBody('Hello Test Client :)'));
            }
            
            yield from $this->sendResponse($stream, $request, $response, $close);
        } catch (StreamClosedException $e) {
            (yield $request->getBody()->getReadableStream())->close();
        } catch (\Throwable $e) {
            (yield $request->getBody()->getReadableStream())->close();
            
            yield from $this->sendErrorResponse($stream, $request, $e);
        }
    }

    protected function handleClosedConnection(\Throwable $e): \Generator
    {
        // TODO: Client dropped connection, cleanup pending awaitables and log this event.
        yield 1;
    }

    protected function sendErrorResponse(DuplexStream $stream, HttpRequest $request, \Throwable $e): \Generator
    {
        fwrite(STDERR, "\n$e\n");
        $response = new HttpResponse(Http::INTERNAL_SERVER_ERROR);
        
        if ($e instanceof StatusException) {
            try {
                $response = $response->withStatus($e->getCode(), $this->debug ? $e->getMessage() : '');
            } catch (\Throwable $e) {}
        }
        
        if ($this->debug) {
            $response = $response->withHeader('Content-Type', 'text/plain');
            $response = $response->withBody(new StringBody($e->getMessage()));
        }
        
        try {
            yield from $this->sendResponse($stream, $request, $response, true);
        } catch (\Throwable $e) {
            fwrite(STDERR, "\n$e\n");
            yield from $this->handleClosedConnection($e);
        }
    }

    protected function sendResponse(DuplexStream $stream, HttpRequest $request, HttpResponse $response, bool $close): \Generator
    {
        // Discard remaining request body before sending response.
        $input = yield $request->getBody()->getReadableStream();
        
        try {
            while (null !== yield $input->read());
        } finally {
            $input->close();
        }
        
        $response = $this->normalizeResponse($request, $response);
        
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
        } else {
            $buffer .= "Connection: keep-alive\r\n";
            $buffer .= "Keep-Alive: timeout=30\r\n";
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
        
        return '';
    }
}
