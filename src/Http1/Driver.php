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
use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Http\StringBody;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Socket\SocketStream;
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
     * Registered result-based HTTP upgrade handlers.
     * 
     * @var array
     */
    protected $upgradeResultHandlers = [];
    
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
    
    public function addUpgradeResultHandler(UpgradeResultHandler $handler)
    {
        $this->upgradeResultHandlers[] = $handler;
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
    public function handleConnection(HttpDriverContext $context, SocketStream $stream, callable $action): Awaitable
    {
        return new Coroutine(function () use ($stream, $action, $context) {
            $remotePeer = $stream->getRemoteAddress();
            
            if ($this->logger) {
                $this->logger->debug(\sprintf('Accepted new connection from %s', $remotePeer));
            }
            
            try {
                $request = yield from $this->parseNextRequest($context, $stream);
                
                if ($request->getProtocolVersion() !== '1.0' && $request->hasHeader('Host')) {
                    try {
                        if (yield from $this->upgradeConnection($stream, $request, $action)) {
                            return;
                        }
                    } catch (\Throwable $e) {
                        return yield from $this->sendErrorResponse($stream, $request, $e);
                    }
                }
                
                $tokens = $request->getHeaderTokens('Connection');
                
                if (\in_array('upgrade', $tokens, true)) {
                    foreach ($tokens as $i => $token) {
                        if ($token === 'keep-alive') {
                            unset($tokens[$i]);
                        }
                    }
                    
                    // Ensure connections with an upgrade token in the connection header are not pipelined / persistent.
                    $request = $request->withHeader('Connection', \implode(', ', \array_merge($tokens, [
                        'close'
                    ])));
                }
                
                $pipeline = Channel::fromGenerator(10, function (Channel $channel) use ($stream, $request, $context) {
                    yield from $this->parseIncomingRequests($context, $stream, $channel, $request);
                });
                
                try {
                    while (null !== ($next = yield $pipeline->receive())) {
                        if (!yield from $this->processRequest($context, $stream, $action, ...$next)) {
                            break;
                        }
                    }
                    
                    $pipeline->close();
                } catch (\Throwable $e) {
                    $pipeline->close($e);
                }
            } finally {
                try {
                    yield $stream->close();
                } finally {
                    if ($this->logger) {
                        $this->logger->debug(\sprintf('Closed connection to %s', $remotePeer));
                    }
                }
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
    protected function parseIncomingRequests(HttpDriverContext $context, SocketStream $stream, Channel $pipeline, HttpRequest $request): \Generator
    {
        try {
            do {
                $request = $request ?? yield from $this->parseNextRequest($context, $stream);
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
    protected function parseNextRequest(HttpDriverContext $context, SocketStream $stream): \Generator
    {
        $request = yield new Timeout(30, new Coroutine($this->parser->parseRequest($stream)));
        $request->getBody()->setCascadeClose(false);
        
        if ($request->getProtocolVersion() == '1.1') {
            if (\in_array('100-continue', $request->getHeaderTokens('Expect'), true)) {
                $request->getBody()->setExpectContinue($stream);
            }
        }
        
        $peerName = $context->peerName;
        $process = true;
        
        if ($request->hasHeader('Host')) {
            $peerName = $request->getHeaderLine('Host');
        } elseif ($request->getProtocolVersion() === '1.1') {
            $process = false;
        }
        
        if ($process) {
            $protocol = isset($stream->getMetadata()['crypto']['protocol']) ? 'https' : 'http';
            $target = $request->getRequestTarget();
            
            if (\substr($target, 0, 1) === '/') {
                $request = $request->withUri(Uri::parse(\sprintf('%s://%s/%s', $protocol, $peerName, \ltrim($target, '/'))));
            } else {
                $request = $request->withUri(Uri::parse(\sprintf('%s://%s/', $protocol, $peerName)));
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
    protected function processRequest(HttpDriverContext $context, SocketStream $socket, callable $action, HttpRequest $request, bool $close): \Generator
    {
        static $remove = [
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
            
            $next = new NextMiddleware($context->middleware, function (HttpRequest $request) use ($action) {
                $response = $action($request);
                
                if ($response instanceof \Generator) {
                    $response = yield from $response;
                }
                
                if (!$response instanceof HttpResponse) {
                    $response = $this->upgradeResult($request, $response);
                    
                    if (!$response instanceof HttpResponse) {
                        $type = \is_object($response) ? \get_class($response) : \gettype($response);
                        
                        throw new \RuntimeException(\sprintf('Expecting HTTP response, server action returned %s', $type));
                    }
                }
                
                $response = $response->withProtocolVersion($request->getProtocolVersion());
                $response = $response->withHeader('Server', 'KoolKode HTTP Server');
                
                return $response;
            });
            
            $response = yield from $next($request);
            
            if ($response->getStatusCode() === Http::SWITCHING_PROTOCOLS) {
                $handler = $response->getAttribute(UpgradeResultHandler::class);
                
                if ($handler instanceof UpgradeResultHandler) {
                    return yield from $this->upgradeResultConnection($handler, $socket, $request, $response);
                }
            }
            
            return yield from $this->sendResponse($socket, $request, $response, $close);
        } catch (\Throwable $e) {
            return yield from $this->sendErrorResponse($socket, $request, $e);
        }
    }
    
    /**
     * Invoke HTTP/1 upgrade handlers in an attempt to update the connection based on the outcome of an action.
     * 
     * @param HttpRequest $request
     * @param mixed $result
     * @return HttpResponse Or null if no connection upgrade is available.
     */
    protected function upgradeResult(HttpRequest $request, $result)
    {
        $protocols = [
            ''
        ];
        
        if (\in_array('upgrade', $request->getHeaderTokens('Connection'), true)) {
            $protocols = \array_merge($request->getHeaderTokens('Upgrade'), $protocols);
        }
        
        foreach ($protocols as $protocol) {
            foreach ($this->upgradeResultHandlers as $handler) {
                if ($handler->isUpgradeSupported($protocol, $request, $result)) {
                    $response = $handler->createUpgradeResponse($request, $result);
                    $response = $response->withAttribute(UpgradeResultHandler::class, $handler);
                    
                    return $response;
                }
            }
        }
        
        return $result;
    }

    /**
     * Have the upgrade handler take control of the given socket connection.
     * 
     * This method will send an HTTP/1 upgrade response before the handler takes over.
     */
    protected function upgradeResultConnection(UpgradeResultHandler $handler, SocketStream $socket, HttpRequest $request, HttpResponse $response): \Generator
    {
        yield $request->getBody()->discard();
        
        $response = $this->normalizeResponse($request, $response);
        
        $buffer = Http::getStatusLine(Http::SWITCHING_PROTOCOLS, $request->getProtocolVersion()) . "\r\n";
        $buffer .= "Connection: upgrade\r\n";
        
        foreach ($response->getHeaders() as $name => $header) {
            $name = Http::normalizeHeaderName($name);
            
            foreach ($header as $value) {
                $buffer .= $name . ': ' . $value . "\r\n";
            }
        }
        
        yield $socket->write($buffer . "\r\n");
        yield $socket->flush();
        
        return yield from $handler->upgradeConnection($socket, $request, $response);
    }
    
    /**
     * Send an HTTP error response (will contain some useful data in debug mode).
     */
    protected function sendErrorResponse(SocketStream $stream, HttpRequest $request, \Throwable $e): \Generator
    {
        $response = new HttpResponse(Http::INTERNAL_SERVER_ERROR);
        
        if ($e instanceof StatusException) {
            $response = $response->withStatus($e->getCode(), $this->debug ? $e->getMessage() : '');
            
            foreach ($response->getHeaders() as $k => $vals) {
                foreach ($vals as $v) {
                    $response = $response->withAddedHeader($k, $v);
                }
            }
        } elseif ($this->logger) {
            $this->logger->critical(sprintf('[%s] "%s" in %s at line %s', \get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        }
        
        if ($this->debug) {
            $response = $response->withHeader('Content-Type', 'text/plain');
            $response = $response->withBody(new StringBody($e->getMessage()));
        }
        
        return yield from $this->sendResponse($stream, $request, $response, true);
    }
    
    /**
     * Coroutine that sends the given HTTP response to the connected client.
     */
    protected function sendResponse(SocketStream $stream, HttpRequest $request, HttpResponse $response, bool $close): \Generator
    {
        // Discard request body in another coroutine.
        $request->getBody()->discard();
        
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
        
        $bodyStream = yield $body->getReadableStream();
        
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
}
