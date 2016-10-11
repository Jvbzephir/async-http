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
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketFactory;

class HttpClient
{    
    protected $userAgent = 'KoolKode HTTP Client';
    
    protected $protocols;
    
    protected $connectors;
    
    public function __construct(HttpConnector ...$connectors)
    {
        $this->connectors = $connectors ?: [
            new Connector()
        ];
        
        $this->protocols = \array_unique(\array_merge(...\array_map(function (HttpConnector $connector) {
            return $connector->getProtocols();
        }, $this->connectors)));
    }

    public function shutdown(): Awaitable
    {
        $close = [];
        
        foreach ($this->connectors as $connector) {
            $close[] = $connector->shutdown();
        }
        
        return new AwaitPending($close);
    }

    public function getProtocols(): array
    {
        return $this->protocols;
    }
    
    public function send(HttpRequest $request): Awaitable
    {
        return new Coroutine(function () use ($request) {
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
            $context->stream = $socket;
            
            return yield $connector->send($context, $request);
        });
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
