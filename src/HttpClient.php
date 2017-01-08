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
use KoolKode\Async\Coroutine;
use KoolKode\Async\DNS\Address;
use KoolKode\Async\Http\Middleware\MiddlewareSupported;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Http\Http1\Connector;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketFactory;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * HTTP client that can be configured to use different connectors to support different HTTP versions.
 * 
 * @author Martin Schröder
 */
class HttpClient implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    use MiddlewareSupported;
    
    /**
     * User agent header to be sent when HTTP requests do not specify this header.
     * 
     * @var string
     */
    protected $userAgent;
    
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
        
        \usort($this->connectors, function (HttpConnector $a, HttpConnector $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
        
        $this->protocols = \array_unique(\array_merge(...\array_map(function (HttpConnector $connector) {
            return $connector->getProtocols();
        }, $this->connectors)));
        
        $this->userAgent = \sprintf('PHP/%s', \PHP_VERSION);
    }

    public function shutdown()
    {
        foreach ($this->connectors as $connector) {
            $connector->shutdown();
        }
    }

    public function addConnector(HttpConnector $connector)
    {
        $this->connectors[] = $connector;
        
        \usort($this->connectors, function (HttpConnector $a, HttpConnector $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }
    
    public function setUserAgent(string $agent)
    {
        $this->userAgent = $agent;
    }
    
    /**
     * Send the given HTTP request and fetch the HTTP response from the server.
     * 
     * @param HttpRequest $request The request to be sent (body will be closed after it has been sent).
     * @return HttpResponse The HTTP response as returned by the target server.
     */
    public function send(HttpRequest $request): Awaitable
    {
        if (!$request->hasHeader('User-Agent')) {
            $request = $request->withHeader('User-Agent', $this->userAgent);
        }
        
        $next = new NextMiddleware($this->middlewares, function (HttpRequest $request) {
            $connecting = new \SplObjectStorage();
            $uri = $request->getUri();
            
            foreach ($this->connectors as $connector) {
                if (!$connector->isRequestSupported($request)) {
                    continue;
                }
                
                $context = yield $connector->getConnectorContext($uri);
                
                if ($context->connected) {
                    foreach ($connecting as $conn) {
                        $connecting[$conn]->dispose();
                    }
                    
                    return yield $connector->send($context, $request);
                }
                
                $connecting[$connector] = $context;
            }
            
            try {
                $socket = yield $this->connectSocket($request->getUri());
                $meta = $socket->getMetadata();
                
                try {
                    $connector = $this->chooseConnector($request, \trim($meta['crypto']['alpn_protocol'] ?? ''), $meta);
                } catch (\Throwable $e) {
                    $socket->close();
                    
                    throw $e;
                }
                
                $context = $connecting[$connector];
                $context->socket = $socket;
                
                $connecting->detach($connector);
                
                return yield $connector->send($context, $request);
            } finally {
                foreach ($connecting as $conn) {
                    $connecting[$conn]->dispose();
                }
            }
        }, $this->logger);
        
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
        
        $factory->setTcpNoDelay(true);
        
        if ($this->protocols && Socket::isAlpnSupported()) {
            $factory->setOption('ssl', 'alpn_protocols', \implode(',', $this->protocols));
        }
        
        return $factory->createSocketStream(5, $uri->getScheme() === 'https');
    }
    
    protected function chooseConnector(HttpRequest $request, string $alpn, array $meta): HttpConnector
    {
        foreach ($this->connectors as $connector) {
            if ($connector->isRequestSupported($request) && $connector->isSupported($alpn, $meta)) {
                return $connector;
            }
        }
        
        throw new \RuntimeException(\sprintf('No HTTP connector could handle negotiated ALPN protocol "%s"', $alpn));
    }
}
