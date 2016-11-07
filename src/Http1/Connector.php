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
use KoolKode\Async\CopyBytes;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Body\BufferedBody;
use KoolKode\Async\Http\Body\FileBody;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpConnectorContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Loop\LoopConfig;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerInterface;

/**
 * Implements the HTTP/1.x protocol on the client side.
 * 
 * @author Martin Schröder
 */
class Connector implements HttpConnector
{
    protected $parser;
    
    protected $keepAlive = true;
    
    protected $expectContinue = true;
    
    protected $pool;
    
    protected $pending;
    
    protected $logger;

    public function __construct(ResponseParser $parser = null, ConnectionManager $pool = null, LoggerInterface $logger = null)
    {
        $this->parser = $parser ?? new ResponseParser();
        $this->pool = $pool ?? new ConnectionManager(8, 15, 100);
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
    public function getPriority(): int
    {
        return 11;
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
    public function isRequestSupported(HttpRequest $request): bool
    {
        return true;
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
            
            yield $this->pool->shutdown();
        });
    }
    
    /**
     * {@inheritdoc}
     */
    public function getConnectorContext(Uri $uri): Awaitable
    {
        return $this->pool->getConnection($uri);
    }
    
    /**
     * {@inheritdoc}
     */
    public function send(HttpConnectorContext $context, HttpRequest $request): Awaitable
    {
        if (!$context instanceof ConnectorContext) {
            throw new \InvalidArgumentException('Invalid connector context passed');
        }
        
        $coroutine = new Coroutine(function () use ($context, $request) {
            try {
                $line = yield from $this->sendRequest($context->socket, $request);
                
                $response = yield from $this->parser->parseResponse($context->socket, $line, $request->getMethod() === Http::HEAD);
                
                if ($response->getStatusCode() === Http::SWITCHING_PROTOCOLS) {
                    $response = $response->withAttribute(SocketStream::class, $context->socket);
                } elseif (!$this->shouldConnectionBeClosed($response)) {
                    $ttl = null;
                    $max = null;
                    
                    if ($response->hasHeader('Keep-Alive')) {
                        $m = null;
                        
                        if (\preg_match("'timeout\s*=\s*([1-9][0-9]*)\s*(?:$|,)'i", $response->getHeaderLine('Keep-Alive'), $m)) {
                            $ttl = (int) $m[1];
                        }
                        
                        if (\preg_match("'max\s*=\s*([1-9][0-9]*)\s*(?:$|,)'i", $response->getHeaderLine('Keep-Alive'), $m)) {
                            $max = (int) $m[1];
                        }
                    }
                    
                    $remaining = ($context->remaining ?? $max) - 1;
                    
                    $uri = $request->getUri();
                    $body = $response->getBody();
                    
                    if ($body instanceof Body) {
                        $body->setCascadeClose(false);
                        
                        (yield $body->getReadableStream())->getAwaitable()->when(function () use ($uri, $context, $ttl, $remaining) {
                            $this->pool->releaseConnection($uri, $context, $ttl, $remaining);
                        });
                    } else {
                        $this->pool->releaseConnection($uri, $context, $ttl, $remaining);
                    }
                }
                
                $response = $response->withoutHeader('Content-Length');
                $response = $response->withoutHeader('Transfer-Encoding');
                
                if ($this->logger) {
                    $reason = \trim($response->getReasonPhrase());
                    
                    if ($reason === '') {
                        $reason = \trim(Http::getReason($response->getStatusCode()));
                    }
                    
                    $this->logger->info('HTTP/{protocol} {status} {reason}', [
                        'protocol' => $response->getProtocolVersion(),
                        'status' => $response->getStatusCode(),
                        'reason' => $reason
                    ]);
                }
                
                return $response;
            } catch (\Throwable $e) {
                $context->socket->close();
                $context->dispose();
                
                throw $e;
            }
        });
        
        $this->pending->attach($coroutine);
        
        $coroutine->when(function () use ($coroutine) {
            $this->pending->detach($coroutine);
        });
        
        return $coroutine;
    }
    
    protected function shouldConnectionBeClosed(HttpResponse $response): bool
    {
        if (!$this->keepAlive) {
            return true;
        }
        
        if ($response->getProtocolVersion() === '1.0' && !\in_array('keep-alive', $response->getHeaderTokenValues('Connection'), true)) {
            return true;
        }
        
        if (!$response->hasHeader('Content-Length') && 'chunked' !== \strtolower($response->getHeaderLine('Transfer-Encoding'))) {
            return true;
        }
        
        return false;
    }
    
    protected function sendRequest(SocketStream $socket, HttpRequest $request): \Generator
    {
        $request = $this->normalizeRequest($request);
        
        if ($this->logger) {
            $this->logger->info('{method} {target} HTTP/{protocol}', [
                'method' => $request->getMethod(),
                'target' => $request->getRequestTarget(),
                'protocol' => $request->getProtocolVersion()
            ]);
        }
        
        $body = $request->getBody();
        $size = yield $body->getSize();
        
        if ($body instanceof FileBody && !$socket->isEncrypted()) {
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
            $len = \strlen($chunk ?? '');
            
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
        
        yield $socket->write($buffer . "\r\n");
        yield $socket->flush();
        
        if ($expect) {
            if (!\preg_match("'^HTTP/1\\.1\s+100(?:$|\s)'i", ($line = yield $socket->readLine()))) {
                try {
                    return $line;
                } finally {
                    if (isset($bodyStream)) {
                        $bodyStream->close();
                    }
                }
            }
        }
        
        if ($body instanceof FileBody && !$socket->isEncrypted()) {
            if ($size) {
                yield LoopConfig::currentFilesystem()->sendfile($body->getFile(), $socket->getSocket(), $size);
            }
        } elseif ($size === null) {
            yield $socket->write(\dechex($len) . "\r\n" . $chunk . "\r\n");
            
            if ($len === $clen) {
                // Align each chunk with length and line breaks to fit into 4 KB payload.
                yield new CopyBytes($bodyStream, $socket, true, null, 4089, function (string $chunk) {
                    return \dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n";
                });
            }
            
            yield $socket->write("0\r\n\r\n");
        } elseif ($size > 0) {
            yield $socket->write($chunk);
            
            if ($len === $clen) {
                yield new CopyBytes($bodyStream, $socket, true, $size - $len);
            }
        }
        
        yield $socket->flush();
    }

    protected function normalizeRequest(HttpRequest $request): HttpRequest
    {
        static $remove = [
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
        
        $tokens = [];
        
        foreach ($request->getHeaderTokenValues('Connection') as $token) {
            if ($token !== 'close' && $token !== 'keep-alive') {
                $tokens[] = $token;
            }
        }
        
        if (empty($tokens)) {
            $request = $request->withoutHeader('Connection');
        } else {
            $request = $request->withHeader('Connection', \implode(', ', $tokens));
        }
        
        foreach ($remove as $name) {
            $request = $request->withoutHeader($name);
        }
        
        return $request->withHeader('Date', \gmdate(Http::DATE_RFC1123));
    }

    protected function serializeHeaders(HttpRequest $request, int $size = null)
    {
        if (\in_array('upgrade', $request->getHeaderTokenValues('Connection'))) {
            $request = $request->withHeader('Connection', 'upgrade');
        } else {
            $request = $request->withHeader('Connection', $this->keepAlive ? 'keep-alive' : 'close');
        }
        
        $buffer = \sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());
        
        if ($this->keepAlive) {
            $buffer .= \sprintf("Keep-Alive: timeout=%u\r\n", $this->pool->getMaxLifetime());
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
