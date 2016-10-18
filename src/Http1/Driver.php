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
use KoolKode\Async\Http\HttpDriver;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Http\StringBody;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableDeflateStream;
use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Timeout;
use KoolKode\Async\Util\Executor;
use Psr\Log\LoggerInterface;

/**
 * Implements the HTTP/1.x protocol on the server side.
 * 
 * @author Martin Schröder
 */
class Driver implements HttpDriver
{
    protected $parser;
    
    protected $logger;
    
    protected $keepAliveSupported = true;
    
    protected $debug = false;
    
    public function __construct(RequestParser $parser = null, LoggerInterface $logger = null)
    {
        $this->parser = $parser ?? new RequestParser();
        $this->logger = $logger;
    }
    
    public function setKeepAliveSupported(bool $keepAlive)
    {
        $this->keepAliveSupported = $keepAlive;
    }
    
    public function setDebug(bool $debug)
    {
        $this->debug = $debug;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getProtocols(): array
    {
        return [
            'http/1.1'
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function handleConnection(DuplexStream $stream, callable $action): Awaitable
    {
        $stream = new PersistentStream($stream);
        $stream->reference();
        
        return new Coroutine(function () use ($stream, $action) {
            $executor = new Executor();
            $jobs = new \SplQueue();
            
            try {
                do {
                    try {
                        $request = yield new Timeout(30, new Coroutine($this->parser->parseRequest($stream)));
                        $request->getBody()->setCascadeClose(false);
                        
                        if ($this->logger) {
                            $this->logger->debug("Persistent / pipelined HTTP request received");
                        }
                        
                        if ($request->getProtocolVersion() == '1.1') {
                            if (!$request->hasHeader('Host')) {
                                throw new StatusException(Http::BAD_REQUEST, 'Missing HTTP Host header');
                            }
                            
                            if (\in_array('100-continue', $request->getHeaderTokens('Expect'), true)) {
                                $request->getBody()->setExpectContinue($stream);
                            }
                        }
                        
                        $close = $this->shouldConnectionBeClosed($request);
                        
                        $jobs->enqueue($executor->execute(function () use ($stream, $action, $request, $close) {
                            yield from $this->processRequest($stream, $action, $request, $close);
                        }));
                        
                        $body = yield $request->getBody()->getReadableStream();
                        
                        // Wait until HTTP request body stream is closed.
                        if ($body instanceof EntityStream) {
                            yield $body->getAwaitable();
                        }
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
                
                yield $stream->close();
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
        
        return false;
    }
    
    protected function processRequest(PersistentStream $stream, callable $action, HttpRequest $request, bool $close): \Generator
    {
        static $remove = [
            'Connection',
            'Keep-Alive',
            'Content-Length',
            'Transfer-Encoding'
        ];
        
        $stream->reference();
        
        if ($this->logger) {
            $this->logger->info(sprintf('%s %s HTTP/%s', $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion()));
        }
        
        try {
            foreach ($remove as $name) {
                $request = $request->withoutHeader($name);
            }
            
            $response = $action($request);
            
            if ($response instanceof \Generator) {
                $response = yield from $response;
            }
            
            if (!$response instanceof HttpResponse) {
                if ($this->logger) {
                    $type = \is_object($response) ? \get_class($response) : \gettype($response);
                    
                    $this->logger->error(\sprintf('Expecting HTTP response, server action returned %s', $type));
                }
                
                $response = new HttpResponse(Http::INTERNAL_SERVER_ERROR);
            }
            
            $response = $response->withProtocolVersion($request->getProtocolVersion());
            $response = $response->withHeader('Server', 'KoolKode HTTP Server');
            
            yield from $this->sendResponse($stream, $request, $response, $close);
        } catch (StreamClosedException $e) {
            (yield $request->getBody()->getReadableStream())->close();
        } catch (\Throwable $e) {
            (yield $request->getBody()->getReadableStream())->close();
            
            yield from $this->sendErrorResponse($stream, $request, $e);
        } finally {
            $stream->close();
        }
    }

    protected function handleClosedConnection(\Throwable $e): \Generator
    {
        // TODO: Client dropped connection, cleanup pending awaitables and log this event.
        yield 1;
    }

    protected function sendErrorResponse(PersistentStream $stream, HttpRequest $request, \Throwable $e): \Generator
    {
        $stream->reference();
        
        try {
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
                yield from $this->handleClosedConnection($e);
            }
        } finally {
            $stream->close();
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
        
        if ($this->logger) {
            $reason = rtrim(' ' . $response->getReasonPhrase());
        
            if ($reason === '') {
                $reason = rtrim(' ' . Http::getReason($response->getStatusCode()));
            }
        
            $this->logger->info(sprintf('HTTP/%s %03u%s', $response->getProtocolVersion(), $response->getStatusCode(), $reason));
        }
        
        $http11 = ($response->getProtocolVersion() == '1.1');
        $nobody = ($request->getMethod() === Http::HEAD || Http::isResponseWithoutBody($response->getStatusCode()));
        $body = $response->getBody();
        $size = yield $body->getSize();
        
        $reason = \trim($response->getReasonPhrase());
        
        if ($reason === '') {
            $reason = Http::getReason($response->getStatusCode());
        }
        
        $buffer = \sprintf("HTTP/%s %u%s\r\n", $response->getProtocolVersion(), $response->getStatusCode(), \rtrim(' ' . $reason));
        
        $compress = null;
        
        if (!$nobody && $size !== 0) {
            $buffer .= $this->enableCompression($request, $compress, $size);
        }
        
        $bodyStream = yield $body->getReadableStream();
        
        if ($compress !== null) {
            $bodyStream = new ReadableDeflateStream($bodyStream, $compress);
        }
        
        if ($nobody || $size === 0) {
            $chunk = null;
            $size = 0;
            $len = 0;
        } else {
            $clen = ($size === null) ? 4089 : 4096;
            $chunk = yield $bodyStream->readBuffer($clen);
            $len = \strlen($chunk);
        }
        
        if ($chunk === null) {
            $size = 0;
        } elseif ($len < $clen) {
            $size = $len;
        }
        
        if ($http11) {
            if ($size === null) {
                $buffer .= "Transfer-Encoding: chunked\r\n";
            } else {
                $buffer .= "Content-Length: $size\r\n";
            }
        } elseif ($size !== null) {
            // HTTP/1.0 responses of unknown size are delimited by EOF / connection closed at the client's side.
            $buffer .= "Content-Length: $size\r\n";
            $close = true;
        }
        
        if ($close) {
            $buffer .= "Connection: close\r\n";
        } else {
            $buffer .= "Connection: keep-alive\r\n";
            $buffer .= "Keep-Alive: timeout=30\r\n";
        }
        
        foreach ($response->getHeaders() as $name => $header) {
            $name = Http::normalizeHeaderName($name);
            
            foreach ($header as $value) {
                $buffer .= $name . ': ' . $value . "\r\n";
            }
        }
        
        yield $stream->write($buffer . "\r\n");
        yield $stream->flush();
        
        try {
            if ($size === null) {
                yield $stream->write(\dechex($len) . "\r\n" . $chunk . "\r\n");
                
                if ($len === $clen) {
                    yield new CopyBytes($bodyStream, $stream, false, null, 4089, function (string $chunk) {
                        return \dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n";
                    });
                }
                
                yield $stream->write("0\r\n\r\n");
            } elseif ($size) {
                yield $stream->write($chunk);
                
                if ($len === $clen) {
                    yield new CopyBytes($bodyStream, $stream, false, $size - $len);
                }
            }
            
            yield $stream->flush();
        } finally {
            $bodyStream->close();
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
