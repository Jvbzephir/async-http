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
use KoolKode\Async\AwaitPending;
use KoolKode\Async\CopyBytes;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpConnectorContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Loop\LoopConfig;
use Psr\Log\LoggerInterface;

class Connector implements HttpConnector
{
    protected $parser;
    
    protected $pool;
    
    protected $keepAlive = true;
    
    protected $debug = false;
    
    protected $pending;
    
    protected $logger;

    public function __construct(ResponseParser $parser = null, LoggerInterface $logger = null)
    {
        $this->parser = $parser ?? new ResponseParser();
        $this->logger = $logger;
        
        $this->pool = new ConnectionPool();
        $this->pending = new \SplObjectStorage();
    }
    
    public function setKeepAlive(bool $keepAlive)
    {
        $this->keepAlive = $keepAlive;
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
        $tasks = [];
        
        try {
            foreach ($this->pending as $pending) {
                $pending->cancel(new \RuntimeException('HTTP connector closed'));
                
                $tasks[] = $pending;
            }
        } finally {
            $this->pending = new \SplObjectStorage();
        }
        
        $tasks[] = $this->pool->shutdown();
        
        return new AwaitPending($tasks);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getConnectorContext(Uri $uri): HttpConnectorContext
    {
        $socket = $this->pool->getConnection($uri);
        $context = new HttpConnectorContext();
        
        if ($socket !== null) {
            $context->connected = true;
            $context->stream = $socket;
        }
        
        return $context;
    }
    
    /**
     * {@inheritdoc}
     */
    public function send(HttpConnectorContext $context, HttpRequest $request): Awaitable
    {
        if ($context->stream instanceof GuardedStream) {
            $stream = $context->stream;
        } else {
            $stream = new GuardedStream($context->stream);
            $stream->reference();
        }
        
        $coroutine = new Coroutine(function () use ($stream, $request) {
            $stream->reference();
            
            try {
                $uri = $request->getUri();
                
                yield from $this->sendRequest($stream, $request);
                
                $response = yield from $this->parser->parseResponse($stream, $request->getMethod() === Http::HEAD);
                $body = $response->getBody();
                
                if ($this->logger) {
                    $reason = rtrim(' ' . $response->getReasonPhrase());
                    
                    if ($reason === '') {
                        $reason = rtrim(' ' . Http::getReason($response->getStatusCode()));
                    }
                    
                    $this->logger->info(sprintf('HTTP/%s %03u%s', $response->getProtocolVersion(), $response->getStatusCode(), $reason));
                }
                
                if ($this->shouldConnectionBeClosed($response)) {
                    $stream->close();
                } else {
                    $bodyStream = yield $body->getReadableStream();
                    
                    if ($bodyStream instanceof EntityStream) {
                        $bodyStream->getAwaitable()->when(function () use ($uri, $stream) {
                            $this->pool->release($uri, $stream);
                        });
                    } else {
                        $stream->close();
                        
                        $this->pool->release($uri, $stream);
                    }
                }
                
                $response = $response->withoutHeader('Content-Length');
                $response = $response->withoutHeader('Transfer-Encoding');
                
                return $response;
            } catch (\Throwable $e) {
                yield $stream->close();
                
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
        
        if (!\in_array('keep-alive', $response->getHeaderTokens('Connection'), true)) {
            return true;
        }
        
        if (!$response->hasHeader('Content-Length') && 'chunked' !== \strtolower($response->getHeaderLine('Transfer-Encoding'))) {
            return true;
        }
        
        return false;
    }
    
    protected function sendRequest(GuardedStream $stream, HttpRequest $request): \Generator
    {
        static $compression;
        
        if ($compression === null) {
            $compression = \function_exists('inflate_init');
        }
        
        $request = $this->normalizeRequest($request);
        
        if ($this->logger) {
            $this->logger->info(sprintf('%s %s HTTP/%s', $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion()));
        }
        
        $body = $request->getBody();
        $size = yield $body->getSize();
        $bodyStream = yield $body->getReadableStream();
        
        $buffer = \sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());
        
        if ($this->keepAlive) {
            $buffer .= "Connection: keep-alive\r\n";
        } else {
            $buffer .= "Connection: close\r\n";
        }
        
        if ($request->getProtocolVersion() == '1.0' && $size === null) {
            $tmp = yield LoopConfig::currentFilesystem()->tempStream();
            
            try {
                $size = yield new CopyBytes($bodyStream, $tmp);
            } catch (\Throwable $e) {
                $tmp->close();
                
                throw $e;
            }
            
            $bodyStream = $tmp;
        }
        
        if ($size === null) {
            $buffer .= "Transfer-Encoding: chunked\r\n";
        } else {
            $buffer .= "Content-Length: $size\r\n";
        }
        
        if ($compression) {
            $buffer .= "Accept-Encoding: gzip, deflate\r\n";
        }
        
        foreach ($request->getHeaders() as $name => $header) {
            $name = Http::normalizeHeaderName($name);
            
            foreach ($header as $value) {
                $buffer .= $name . ': ' . $value . "\r\n";
            }
        }
        
        yield $stream->write($buffer . "\r\n");
        yield $stream->flush();
        
        // TODO: Add support for Expect: 100-continue
        
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

    protected function normalizeRequest(HttpRequest $request): HttpRequest
    {
        static $remove = [
            'Accept-Encoding',
            'Connection',
            'Content-Encoding',
            'Content-Length',
            'Keep-Alive',
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
}
