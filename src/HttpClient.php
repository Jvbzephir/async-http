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
use KoolKode\Async\Placeholder;
use KoolKode\Async\Promise;
use KoolKode\Async\Channel\IterableChannel;
use KoolKode\Async\Channel\Pipeline;
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
    
    protected $baseUri;
    
    protected $clientSettings;

    public function __construct(HttpConnector ...$connectors)
    {
        if (empty($connectors)) {
            throw new \InvalidArgumentException('At least one HTTP connector is required');
        }
        
        $this->connectors = $connectors;
        $this->clientSettings = new ClientSettings();
        
        \usort($this->connectors, function (HttpConnector $a, HttpConnector $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }
    
    public function withBaseUri($uri): self
    {
        $client = clone $this;
        $client->baseUri = Uri::parse($uri)->withQueryParams([])->withFragment('');
        
        return $client;
    }

    public function request(string $uri, string $method = Http::GET, array $headers = []): RequestBuilder
    {
        $builder = new RequestBuilder($this, $this->normalizeUri(Uri::parse($uri)), $method);
        
        foreach ($headers as $k => $v) {
            $builder->header($k, ...(array) $v);
        }
        
        return $builder->attribute(ClientSettings::class, $this->clientSettings);
    }
    
    public function send(Context $context, $request): Promise
    {
        if ($request instanceof RequestBuilder) {
            $request = $request->build();
        }
        
        if (!$request instanceof HttpRequest) {
            throw new \InvalidArgumentException('HttpRequest or RequestBuilder expected');
        }
        
        $request = $request->withUri($this->normalizeUri($request->getUri()));
        
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
    
    public function sendAll(array $requests, int $concurrency = 8): Pipeline
    {
        $input = [];
        
        foreach ($requests as $request) {
            if ($request instanceof RequestBuilder) {
                $request = $request->build();
            }
            
            if (!$request instanceof HttpRequest) {
                throw new \InvalidArgumentException('HttpRequest or RequestBuilder expected');
            }
            
            $input[] = $request;
        }
        
        $pipeline = new Pipeline(new IterableChannel($input), $concurrency);
        
        return $pipeline->map(function (Context $context, HttpRequest $request) {
            return $this->send($context, $request);
        });
    }
    
    protected function normalizeUri(Uri $uri): Uri
    {
        $path = $uri->getPath();
        
        if ($uri->getHost() !== '') {
            return $uri;
        }
        
        if ('/' == ($path[0] ?? null)) {
            $target = $this->baseUri->withPath($path);
        } else {
            $path = \rtrim($this->baseUri->getPath(), '/') . '/' . \ltrim($path, '/');
            $target = $this->baseUri->withPath($path);
        }
        
        $target = $target->withQuery($uri->getQuery());
        $target = $target->withFragment($uri->getFragment());
        
        return $target;
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
