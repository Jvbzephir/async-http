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
use KoolKode\Async\Http\FileBody;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpConnectorContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Loop\LoopConfig;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerInterface;
use KoolKode\Async\Http\BufferedBody;

/**
 * Implements the HTTP/1.x protocol on the client side.
 * 
 * @author Martin Schröder
 */
class Connector implements HttpConnector
{
    protected $parser;
    
    protected $conns = [];
    
    protected $keepAlive = true;
    
    protected $maxLifetime = 15;
    
    protected $expectContinue = true;
    
    protected $pending;
    
    protected $logger;

    public function __construct(ResponseParser $parser = null, LoggerInterface $logger = null)
    {
        $this->parser = $parser ?? new ResponseParser();
        $this->logger = $logger;
        
        $this->pending = new \SplObjectStorage();
    }
    
    public function setKeepAlive(bool $keepAlive)
    {
        $this->keepAlive = $keepAlive;
    }
    
    public function setExpectContinue($expect)
    {
        $this->expectContinue = $expect;
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
    public function isSupported(string $protocol, array $meta = []): bool
    {
        return \in_array($protocol, [
            'http/1.1',
            ''
        ], true);
    }
    
    /**
     * {@inheritdoc}
     */
    public function shutdown(): Awaitable
    {
        return new Coroutine(function () {
            try {
                foreach ($this->pending as $pending) {
                    foreach ($pending->cancel(new \RuntimeException('HTTP connector closed')) as $task) {
                        yield $task;
                    }
                }
            } finally {
                $this->pending = new \SplObjectStorage();
            }
            
            foreach ($this->conns as $conn) {
                while (!$conn->isEmpty()) {
                    (yield $conn->dequeue())[0]->close();
                }
            }
        });
    }
    
    /**
     * {@inheritdoc}
     */
    public function getConnectorContext(Uri $uri): HttpConnectorContext
    {
        list ($socket, $remaining) = $this->getConnection($uri);
        $context = new ConnectorContext();
        
        if ($socket !== null && $remaining) {
            $context->connected = true;
            $context->socket = $socket;
            $context->remaining = $remaining;
        }
        
        return $context;
    }
    
    /**
     * {@inheritdoc}
     */
    public function send(HttpConnectorContext $context, HttpRequest $request): Awaitable
    {
        $coroutine = new Coroutine(function () use ($context, $request) {
            try {
                $line = yield from $this->sendRequest($context->socket, $request);
                
                $response = yield from $this->parser->parseResponse($context->socket, $line, $request->getMethod() === Http::HEAD);
                
                if (!$this->shouldConnectionBeClosed($response)) {
                    $ttl = $this->maxLifetime;
                    $max = 100;
                    
                    if ($response->hasHeader('Keep-Alive')) {
                        $m = null;
                        
                        if (\preg_match("'timeout\s*=\s*([1-9][0-9]*)\s*(?:$|,)'i", $response->getHeaderLine('Keep-Alive'), $m)) {
                            $ttl = (int) $m[1];
                        }
                        
                        if (\preg_match("'max\s*=\s*([1-9][0-9]*)\s*(?:$|,)'i", $response->getHeaderLine('Keep-Alive'), $m)) {
                            $max = \min($max, (int) $m[1]);
                        }
                    }
                    
                    $remaining = ($context->remaining ?? $max) - 1;
                    
                    $uri = $request->getUri();
                    $body = $response->getBody();
                    
                    if ($body instanceof Body) {
                        $body->setCascadeClose(false);
                        
                        (yield $body->getReadableStream())->getAwaitable()->when(function () use ($uri, $context, $ttl, $remaining) {
                            $this->releaseConnection($uri, $context->socket, \min($this->maxLifetime, $ttl), $remaining);
                        });
                    } else {
                        $this->releaseConnection($uri, $context->socket, $this->maxLifetime, $remaining);
                    }
                }
                
                $response = $response->withoutHeader('Content-Length');
                $response = $response->withoutHeader('Transfer-Encoding');
                
                if ($this->logger) {
                    $reason = \rtrim(' ' . $response->getReasonPhrase());
                    
                    if ($reason === '') {
                        $reason = \rtrim(' ' . Http::getReason($response->getStatusCode()));
                    }
                    
                    $this->logger->info(\sprintf('HTTP/%s %03u%s', $response->getProtocolVersion(), $response->getStatusCode(), $reason));
                }
                
                return $response;
            } catch (\Throwable $e) {
                $context->socket->close();
                
                throw $e;
            }
        });
        
        $this->pending->attach($coroutine);
        
        $coroutine->when(function () use ($coroutine) {
            $this->pending->detach($coroutine);
        });
        
        return $coroutine;
    }
    
    protected function getConnection(Uri $uri)
    {
        $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
        $time = \time();
        
        if (isset($this->conns[$key])) {
            while (!$this->conns[$key]->isEmpty()) {
                if ($this->logger) {
                    $this->logger->debug("Reusing persistent connection: $key");
                }
                
                list ($conn, $expires, $remaining) = $this->conns[$key]->dequeue();
                
                if ($expires > $time && $conn->isAlive()) {
                    return [
                        $conn,
                        $remaining
                    ];
                }
            }
            
            unset($this->conns[$key]);
        }
    }

    protected function releaseConnection(Uri $uri, SocketStream $conn, int $ttl, int $remaining)
    {
        if ($remaining > 0 && $ttl > 0 && $conn->isAlive()) {
            $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
            
            if (empty($this->conns[$key])) {
                $this->conns[$key] = new \SplQueue();
            }
            
            $this->conns[$key]->enqueue([
                $conn,
                \time() + $ttl,
                $remaining
            ]);
            
            if ($this->logger) {
                $this->logger->debug("Released persistent connection: $key");
            }
            
            return;
        }
        
        $conn->close();
    }

    protected function shouldConnectionBeClosed(HttpResponse $response): bool
    {
        if (!$this->keepAlive) {
            return true;
        }
        
        if ($response->getProtocolVersion() === '1.0' && !\in_array('keep-alive', $response->getHeaderTokens('Connection'), true)) {
            return true;
        }
        
        if (!$response->hasHeader('Content-Length') && 'chunked' !== \strtolower($response->getHeaderLine('Transfer-Encoding'))) {
            return true;
        }
        
        return false;
    }
    
    protected function sendRequest(SocketStream $stream, HttpRequest $request): \Generator
    {
        $request = $this->normalizeRequest($request);
        
        if ($this->logger) {
            $this->logger->info(\sprintf('%s %s HTTP/%s', $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion()));
        }
        
        $body = $request->getBody();
        $size = yield $body->getSize();
        
        if ($body instanceof FileBody && !$stream->isEncrypted()) {
            $chunk = $size ? '' : null;
        } else {
            if ($request->getProtocolVersion() == '1.0' && $size === null) {
                if (!$body->isCached()) {
                    $body = new BufferedBody(yield $body->getReadableStream());
                }
                
                yield $body->discard();
                
                $size = yield $body->getSize();
            }
            
            $bodyStream = yield $body->getReadableStream();
            
            $clen = ($size === null) ? 4089 : 4096;
            $chunk = yield $bodyStream->readBuffer($clen);
            $len = \strlen($chunk);
            
            if ($chunk === null) {
                $size = 0;
            } elseif ($len < $clen) {
                $size = $len;
            }
        }
        
        $buffer = $this->serializeHeaders($request, $size);
        $expect = false;
        
        if ($this->expectContinue && $chunk !== null && $request->getProtocolVersion() == '1.1') {
            $expect = true;
            $buffer .= "Expect: 100-continue\r\n";
        }
        
        yield $stream->write($buffer . "\r\n");
        yield $stream->flush();
        
        if ($expect) {
            if (!\preg_match("'^HTTP/1\\.1\s+100(?:$|\s)'i", ($line = yield $stream->readLine()))) {
                try {
                    return $line;
                } finally {
                    if (isset($bodyStream)) {
                        $bodyStream->close();
                    }
                }
            }
        }
        
        if ($body instanceof FileBody && !$stream->isEncrypted()) {
            if ($size) {
                yield LoopConfig::currentFilesystem()->sendfile($body->getFile(), $stream->getSocket(), $size);
            }
        } elseif ($size === null) {
            yield $stream->write(\dechex($len) . "\r\n" . $chunk . "\r\n");
            
            if ($len === $clen) {
                // Align each chunk with length and line breaks to fit into 4 KB payload.
                yield new CopyBytes($bodyStream, $stream, true, null, 4089, function (string $chunk) {
                    return \dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n";
                });
            }
            
            yield $stream->write("0\r\n\r\n");
        } elseif ($size > 0) {
            yield $stream->write($chunk);
            
            if ($len === $clen) {
                yield new CopyBytes($bodyStream, $stream, true, $size - $len);
            }
        }
        
        yield $stream->flush();
    }

    protected function normalizeRequest(HttpRequest $request): HttpRequest
    {
        static $remove = [
            'Connection',
            'Content-Length',
            'Expect',
            'Keep-Alive',
            'TE',
            'Trailer',
            'Transfer-Encoding'
        ];
        
        $version = $request->getProtocolVersion();
        
        switch ($version) {
            case '1.0':
            case '1.1':
                // Everything fine, version is supported.
                break;
            default:
                $request = $request->withProtocolVersion('1.1');
        }
        
        foreach ($remove as $name) {
            $request = $request->withoutHeader($name);
        }
        
        return $request->withHeader('Date', \gmdate(Http::DATE_RFC1123));
    }

    protected function serializeHeaders(HttpRequest $request, int $size = null)
    {
        $buffer = \sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());
        $buffer .= \sprintf("Connection: %s\r\n", $this->keepAlive ? 'keep-alive' : 'close');
        
        if ($this->keepAlive) {
            $buffer .= \sprintf("Keep-Alive: timeout=%u\r\n", $this->maxLifetime);
        }
        
        if ($size === null) {
            $buffer .= "Transfer-Encoding: chunked\r\n";
        } else {
            $buffer .= "Content-Length: $size\r\n";
        }
        
        foreach ($request->getHeaders() as $name => $header) {
            $name = Http::normalizeHeaderName($name);
            
            foreach ($header as $value) {
                $buffer .= $name . ': ' . $value . "\r\n";
            }
        }
        
        return $buffer;
    }
}
