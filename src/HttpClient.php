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
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Http\Middleware\MiddlewareSupported;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Socket\ClientEncryption;
use KoolKode\Async\Socket\ClientFactory;
use KoolKode\Async\Socket\Connect;

class HttpClient
{
    use MiddlewareSupported;
    
    protected $connectors;
    
    protected $connecting = [];

    public function __construct(HttpConnector ...$connectors)
    {
        if (empty($connectors)) {
            throw new \InvalidArgumentException('At least one HTTP connector is required');
        }
        
        $this->connectors = $connectors;
        
        \usort($this->connectors, function (HttpConnector $a, HttpConnector $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }
    
    public function send(Context $context, HttpRequest $request): Promise
    {
        $next = new NextMiddleware($this->middlewares, function (Context $context, HttpRequest $request) {
            $uri = $request->getUri();
            $key = $uri->getScheme() . '://' . $uri->getHostWithPort(true);
            
            while (isset($this->connecting[$key])) {
                $this->connecting[$key][] = $placeholder = new Placeholder($context);
                
                yield $placeholder->promise();
            }
            
            $supported = [];
            
            foreach ($this->connectors as $connector) {
                if ($connector->isRequestSupported($request)) {
                    if (yield $connector->isConnected($context, $key)) {
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
            
            $this->connecting[$key] = [];
            
            try {
                $socket = yield from $this->connectSocket($context, $uri, $protocols);
                
                try {
                    $connector = $this->chooseConnector($supported, $socket->getAlpnProtocol() ?? '');
                } catch (\Throwable $e) {
                    $socket->close();
                    
                    throw $e;
                }
                
                $promise = $connector->send($context, $request, $socket);
            } finally {
                $connecting = $this->connecting[$key];
                unset($this->connecting[$key]);
                
                foreach ($connecting as $placeholder) {
                    $placeholder->resolve();
                }
            }
            
            return yield $promise;
        });
        
        return $context->task($next($context, $request));
    }
    
    public function sendAll(Context $context, array $requests, callable $callback): Promise
    {
        $defer = new Deferred($context);
        $pending = $count = \count($requests);
        
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
    
    public function get(Context $context, $uri): Promise
    {
        return $this->send($context, new HttpRequest($uri));
    }
    
    public function getJson(Context $context, $uri): Promise
    {
        return $context->task(function (Context $context) use ($uri) {
            $response = yield $this->send($context, new HttpRequest($uri, Http::GET, [
                'Accept' => 'application/json, */*;q=0.5'
            ]));
            
            return \json_decode(yield $response->getBody()->getContents($context), true);
        });
    }
    
    public function postForm(Context $context, $uri, array $fields): Promise
    {
        return $this->send($context, new HttpRequest($uri, Http::POST, [
            'Content-Type' => Http::FORM_ENCODED
        ], new StringBody(\http_build_query($fields, '', '&', \PHP_QUERY_RFC3986))));
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
        $connect = (new Connect())->withTcpNodelay(true);
        
        return yield $factory->connect($context, $connect);
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
