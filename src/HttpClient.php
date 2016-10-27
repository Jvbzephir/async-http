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

use KoolKode\Async\Awaitable;
use KoolKode\Async\AwaitPending;
use KoolKode\Async\Coroutine;
use KoolKode\Async\DNS\Address;
use KoolKode\Async\Http\Http1\Connector;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketFactory;

/**
 * HTTP client that can be configured to use different connectors to support different HTTP versions.
 * 
 * @author Martin Schröder
 */
class HttpClient
{
    /**
     * User agent header to be sent when HTTP requests do not specify this header.
     * 
     * @var string
     */
    protected $userAgent = 'KoolKode HTTP Client';
    
    /**
     * Lists ALPN protocols supported by registered connectors.
     * 
     * @var array
     */
    protected $protocols;
    
    /**
     * Registered HTTP connectors.
     * 
     * @var array
     */
    protected $connectors;
    
    /**
     * Registered HTTP client middleware.
     * 
     * @var \SplPriorityQueue
     */
    protected $middleware;
    
    /**
     * Create a new HTTP client using the given connectors.
     * 
     * Will auto-create an HTTP/1.x connector if no other connector is given. 
     * 
     * @param HttpConnector ...$connectors
     */
    public function __construct(HttpConnector ...$connectors)
    {
        $this->connectors = $connectors ?: [
            new Connector()
        ];
        
        $this->protocols = \array_unique(\array_merge(...\array_map(function (HttpConnector $connector) {
            return $connector->getProtocols();
        }, $this->connectors)));
        
        $this->middleware = new \SplPriorityQueue();
    }

    public function shutdown(): Awaitable
    {
        $close = [];
        
        foreach ($this->connectors as $connector) {
            $close[] = $connector->shutdown();
        }
        
        return new AwaitPending($close);
    }

    public function addMiddleware(callable $middleware, int $priority = 0)
    {
        $this->middleware->insert($middleware, $priority);
    }
    
    /**
     * Send the given HTTP request and fetch the HTTP response from the server.
     * 
     * @param HttpRequest $request The request to be sent (body will be closed after it has been sent).
     * @return HttpResponse The HTTP response as returned by the target server.
     */
    public function send(HttpRequest $request): Awaitable
    {
        $next = new NextMiddleware($this->middleware, function (HttpRequest $request) {
            if (!$request->hasHeader('User-Agent')) {
                $request = $request->withHeader('User-Agent', $this->userAgent);
            }
            
            $uri = $request->getUri();
            
            foreach ($this->connectors as $connector) {
                $context = $connector->getConnectorContext($uri);
                
                if ($context->connected) {
                    return yield $connector->send($context, $request);
                }
            }
            
            $socket = yield $this->connectSocket($request->getUri());
            $meta = $socket->getMetadata();
            
            try {
                $connector = $this->chooseConnector(\trim($meta['crypto']['alpn_protocol'] ?? ''), $meta);
            } catch (\Throwable $e) {
                $socket->close();
                
                throw $e;
            }
            
            $context = new HttpConnectorContext();
            $context->socket = $socket;
            
            return yield $connector->send($context, $request);
        });
        
        return new Coroutine($next($request));
    }
    
    protected function connectSocket(Uri $uri): Awaitable
    {
        $host = $uri->getHost();
        
        if (Address::isResolved($host)) {
            $factory = new SocketFactory(new Address($host) . ':' . $uri->getPort(), 'tcp');
        } else {
            $factory = new SocketFactory($uri->getHostWithPort(true), 'tcp');
        }
        
        if ($this->protocols && Socket::isAlpnSupported()) {
            $factory->setOption('ssl', 'alpn_protocols', \implode(',', $this->protocols));
        }
        
        return $factory->createSocketStream(5, $uri->getScheme() === 'https');
    }
    
    protected function chooseConnector(string $alpn, array $meta): HttpConnector
    {
        foreach ($this->connectors as $connector) {
            if ($connector->isSupported($alpn, $meta)) {
                return $connector;
            }
        }
        
        throw new \RuntimeException(\sprintf('No HTTP connector could handle negotiated ALPN protocol "%s"', $alpn));
    }
}
