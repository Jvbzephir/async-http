<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http;

use KoolKode\Async\Context;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Deferred;
use KoolKode\Async\Placeholder;
use KoolKode\Async\Promise;
use KoolKode\Async\Http\Http1\Http1Connector;
use KoolKode\Async\Http\Middleware\MiddlewareSupported;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Socket\ClientEncryption;
use KoolKode\Async\Socket\ClientFactory;

class HttpClient
{
    use MiddlewareSupported;
    
    protected $connectors;
    
    protected $connecting = [];

    public function __construct()
    {
        $this->connectors = [
            new Http1Connector()
        ];
        
        \usort($this->connectors, function (HttpConnector $a, HttpConnector $b) {
            return $a->getPriority() <=> $b->getPriority();
        });
    }
    
    public function close(): void
    {
        
    }
    
    public function send(Context $context, HttpRequest $request): Promise
    {
        $next = new NextMiddleware($this->middlewares, function (Context $context, HttpRequest $request) {
            $uri = $request->getUri();
            $key = $uri->getScheme() . '://' . $uri->getHostWithPort(true);
            
            if (isset($this->connecting[$key])) {
                yield $this->connecting[$key];
            }
            
            $supported = [];
            
            foreach ($this->connectors as $connector) {
                if ($connector->isRequestSupported($request)) {
                    if ($connector->isConnected($key)) {
                        return yield $connector->send($context, $request);
                    }
                    
                    $supported[] = $connector;
                }
            }
            
            if (empty($supported)) {
                throw new \RuntimeException('Unable to find connector that supports the given HTTP request');
            }
            
            $protocols = \array_merge(...\array_map(function (HttpConnector $connector) {
                return $connector->getProtocols();
            }, $supported));
            
            $defer = new Deferred($context);
            $this->connecting[$key] = $defer->promise();
            
            try {
                $socket = yield from $this->connectSocket($context, $uri, $protocols);
            } finally {
                unset($this->connecting[$key]);
                
                $defer->resolve();
            }
            
            try {
                $connector = $this->chooseConnector($supported, $socket->getAlpnProtocol() ?? '');
            } catch (\Throwable $e) {
                $socket->close();
                
                throw $e;
            }
            
            return yield $connector->send($context, $request, $socket);
        });
        
        return $context->task($next($context, $request));
    }
    
    public function sendAll(Context $context, array $requests, callable $callback): Promise
    {
        $cancel = $context->cancellationHandler();
        
        $defer = new Placeholder($context, function (Placeholder $p, string $reason, ?\Throwable $e = null) use ($cancel) {
            $cancel($reason, $e);
        });
        
        $pending = $count = \count($requests);
        $context = $context->cancellable($cancel);
        
        foreach ($requests as $request) {
            $this->send($context, $request)->when(static function ($e, $v = null) use ($context, $defer, $count, & $pending, $callback) {
                $generator = Coroutine::generate($callback, $context, $e, $v);
                
                $context->task($generator)->when(static function () use ($defer, $count, & $pending) {
                    if (--$pending === 0) {
                        $defer->resolve($count);
                    }
                });
            });
        }
        
        return $defer->promise();
    }

    protected function connectSocket(Context $context, Uri $uri, array $protocols): \Generator
    {
        $tls = null;
        
        if ($uri->getScheme() == 'https') {
            $tls = new ClientEncryption();
            $tls = $tls->withPeerName($uri->getHostWithPort());
            
            if ($protocols) {
                $tls = $tls->withAlpnProtocols(...$protocols);
            }
        }
        
        $factory = new ClientFactory('tcp://' . $uri->getHostWithPort(true), $tls);
        
        return yield $factory->connect($context);
    }
    
    protected function chooseConnector(array $connectors, string $alpn): HttpConnector
    {
        foreach ($connectors as $connector) {
            if ($connector->isSupported($alpn)) {
                return $connector;
            }
        }
        
        throw new \RuntimeException(\sprintf('No connector supports ALPN protocol "%s"', $alpn));
    }
}
