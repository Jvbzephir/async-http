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
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\ReadableDeflateStream;
use KoolKode\Async\Timeout;
use KoolKode\Async\Util\Channel;
use Psr\Log\LoggerInterface;

/**
 * Implements the HTTP/1.x protocol on the server side.
 * 
 * @author Martin Schröder
 */
class Driver implements HttpDriver
{
    /**
     * HTTP request parser being used to parse incoming requests.
     * 
     * @var RequestParser
     */
    protected $parser;
    
    /**
     * Support HTTP keep-alive connections?
     * 
     * @var bool
     */
    protected $keepAliveSupported = true;
    
    /**
     * Turn on debug mode (returns readable error messages).
     * 
     * @var bool
     */
    protected $debug = false;
    
    /**
     * Registered HTTP connection upgrade handlers.
     * 
     * @var array
     */
    protected $upgradeHandlers = [];
    
    /**
     * Logger instance.
     * 
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * Create a new HTTP/1 driver.
     * 
     * @param RequestParser $parser HTTP request parser.
     * @param LoggerInterface $logger Optional logger instance.
     */
    public function __construct(RequestParser $parser = null, LoggerInterface $logger = null)
    {
        $this->parser = $parser ?? new RequestParser();
        $this->logger = $logger;
    }
    
    /**
     * Toggle support for HTTP keep-alive connections.
     */
    public function setKeepAliveSupported(bool $keepAlive)
    {
        $this->keepAliveSupported = $keepAlive;
    }
    
    /**
     * Toggle debug mode setting.
     */
    public function setDebug(bool $debug)
    {
        $this->debug = $debug;
    }
    
    /**
     * Add an HTTP/1 connection upgrade handler to the driver.
     * 
     * @param UpgradeHandler $handler
     */
    public function addUpgradeHandler(UpgradeHandler $handler)
    {
        $this->upgradeHandlers[] = $handler;
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
    public function handleConnection(SocketStream $stream, callable $action): Awaitable
    {
        return new Coroutine(function () use ($stream, $action) {
            if ($this->logger) {
                $this->logger->debug(\sprintf('Accepted new connection from %s', \stream_socket_get_name($stream->getSocket(), true)));
            }
            
            try {
                $request = yield from $this->parseNextRequest($stream);
                
                if (yield from $this->upgradeConnection($stream, $request, $action)) {
                    return;
                }
                
                $pipeline = Channel::fromGenerator(10, function (Channel $channel) use ($stream, $request) {
                    yield from $this->parseIncomingRequests($stream, $channel, $request);
                });
                
                try {
                    while (null !== ($next = yield $pipeline->receive())) {
                        if (!yield from $this->processRequest($stream, $action, ...$next)) {
                            break;
                        }
                    }
                    
                    $pipeline->close();
                } catch (\Throwable $e) {
                    $pipeline->close($e);
                }
            } finally {
                if ($this->logger) {
                    $this->logger->debug('Client disconnected');
                }
                
                $stream->close();
            }
        });
    }
    
    /**
     * Consult all registered upgrade handlers in order to upgrade the connection as needed.
     * 
     * @param SocketStream $socket
     * @param HttpRequest $request
     * @param callable $action
     * @return bool Returns true when a connection upgrade has been performed.
     */
    protected function upgradeConnection(SocketStream $socket, HttpRequest $request, callable $action): \Generator
    {
        $protocols = [
            ''
        ];
        
        if (\in_array('upgrade', $request->getHeaderTokens('Connection'), true)) {
            $protocols = \array_merge($request->getHeaderTokens('Upgrade'), $protocols);
        }
        
        foreach ($protocols as $protocol) {
            foreach ($this->upgradeHandlers as $handler) {
                if ($handler->isUpgradeSupported($protocol, $request)) {
                    yield from $handler->upgradeConnection($socket, $request, $action);
                    
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Coroutine that parses incoming requests and queues them into the request pipeline.
     * 
     * @param DuplexStream $stream Stream being used to transmit HTTP messages.
     * @param Channel $pipeline HTTP request pipeline.
     * @param HttpRequest $request First HTTP request within the pipeline.
     */
    protected function parseIncomingRequests(SocketStream $stream, Channel $pipeline, HttpRequest $request): \Generator
    {
        try {
            do {
                $request = $request ?? yield from $this->parseNextRequest($stream);
                $close = $this->shouldConnectionBeClosed($request);
                
                yield $pipeline->send([
                    $request,
                    $close
                ]);
                
                yield (yield $request->getBody()->getReadableStream())->getAwaitable();
                
                $request = null;
            } while (!$close);
            
            $pipeline->close();
        } catch (\Throwable $e) {
            $pipeline->close($e);
        }
    }

    /**
     * Parse the next HTTP request that arrives via the given stream.
     * 
     * @param SocketStream $stream
     * @return HttpRequest
     */
    protected function parseNextRequest(SocketStream $stream): \Generator
    {
        $request = yield new Timeout(30, new Coroutine($this->parser->parseRequest($stream)));
        $request->getBody()->setCascadeClose(false);
        
        if ($request->getProtocolVersion() == '1.1') {
            if (\in_array('100-continue', $request->getHeaderTokens('Expect'), true)) {
                $request->getBody()->setExpectContinue($stream);
            }
        }
        
        return $request;
    }
    
    /**
     * Check if the HTTP message stream should be closed after the given request has been processed.
     */
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
    
    /**
     * Dispatch the given HTTP request to the given action.
     */
    protected function processRequest(SocketStream $stream, callable $action, HttpRequest $request, bool $close): \Generator
    {
        static $remove = [
            'Connection',
            'Keep-Alive',
            'Content-Length',
            'Transfer-Encoding'
        ];
        
        if ($this->logger) {
            $this->logger->info(\sprintf('%s %s HTTP/%s', $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion()));
        }
        
        try {
            if ($request->getProtocolVersion() == '1.1' && !$request->hasHeader('Host')) {
                throw new StatusException(Http::BAD_REQUEST, 'Missing HTTP Host header');
            }
            
            foreach ($remove as $name) {
                $request = $request->withoutHeader($name);
            }
            
            $response = $action($request);
            
            if ($response instanceof \Generator) {
                $response = yield from $response;
            }
            
            if (!$response instanceof HttpResponse) {
                $type = \is_object($response) ? \get_class($response) : \gettype($response);
                
                throw new \RuntimeException(\sprintf('Expecting HTTP response, server action returned %s', $type));
            }
            
            $response = $response->withProtocolVersion($request->getProtocolVersion());
            $response = $response->withHeader('Server', 'KoolKode HTTP Server');
            
            return yield from $this->sendResponse($stream, $request, $response, $close);
        } catch (\Throwable $e) {
            return yield from $this->sendErrorResponse($stream, $request, $e);
        }
    }

    /**
     * Send an HTTP error response (will contain some useful data in debug mode).
     */
    protected function sendErrorResponse(SocketStream $stream, HttpRequest $request, \Throwable $e): \Generator
    {
        $response = new HttpResponse(Http::INTERNAL_SERVER_ERROR);
        
        if ($e instanceof StatusException) {
            $response = $response->withStatus($e->getCode(), $this->debug ? $e->getMessage() : '');
        }
        
        if ($this->debug) {
            $response = $response->withHeader('Content-Type', 'text/plain');
            $response = $response->withBody(new StringBody($e->getMessage()));
        }
        
        return yield from $this->sendResponse($stream, $request, $response, true);
    }
    
    /**
     * Coroutine that discards the remainder of the given HTTP request body.
     */
    protected function discardRequestBody(Body $body): \Generator
    {
        $body->setExpectContinue(null);
        
        $input = yield $body->getReadableStream();
        
        try {
            while (null !== yield $input->read());
        } finally {
            $input->close();
        }
    }

    /**
     * Coroutine that sends the given HTTP response to the connected client.
     */
    protected function sendResponse(SocketStream $stream, HttpRequest $request, HttpResponse $response, bool $close): \Generator
    {
        new Coroutine($this->discardRequestBody($request->getBody()));
        
        $response = $this->normalizeResponse($request, $response);
        
        if ($this->logger) {
            $reason = \rtrim(' ' . $response->getReasonPhrase());
            
            if ($reason === '') {
                $reason = \rtrim(' ' . Http::getReason($response->getStatusCode()));
            }
            
            $this->logger->info(\sprintf('HTTP/%s %03u%s', $response->getProtocolVersion(), $response->getStatusCode(), $reason));
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
            $buffer .= "Content-Length: $size\r\n";
        } else {
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
            if ($http11 && $size === null) {
                yield $stream->write(\dechex($len) . "\r\n" . $chunk . "\r\n");
                
                if ($len === $clen) {
                    yield new CopyBytes($bodyStream, $stream, false, null, 4089, function (string $chunk) {
                        return \dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n";
                    });
                }
                
                yield $stream->write("0\r\n\r\n");
            } elseif ($chunk !== null) {
                yield $stream->write($chunk);
                
                if ($len === $clen) {
                    yield new CopyBytes($bodyStream, $stream, false, ($size === null) ? null : ($size - $len));
                }
            }
            
            yield $stream->flush();
        } finally {
            $bodyStream->close();
        }
        
        return !$close;
    }

    /**
     * Normalize HTTP response object prior to being sent to the client.
     */
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

    /**
     * Enable HTTP body compression if available on the server and supported by the client.
     */
    protected function enableCompression(HttpRequest $request, int & $compress = null, int & $size = null): string
    {
        static $available;
        
        static $map = [
            'gzip' => \ZLIB_ENCODING_GZIP,
            'x-gzip' => \ZLIB_ENCODING_GZIP,
            'deflate' => \ZLIB_ENCODING_DEFLATE
        ];
        
        if ($available ?? ($available = \function_exists('deflate_init'))) {
            $accept = $request->getHeaderTokens('Accept-Encoding');
            
            foreach ($accept as $key) {
                if (isset($map[$key])) {
                    $compress = $map[$key];
                    $size = null;
                    
                    return \sprintf("Content-Encoding: %s\r\n", $key);
                }
            }
        }
        
        return '';
    }
}
