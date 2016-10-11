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
use KoolKode\Async\Http\Http1\Connector;
use KoolKode\Async\Http\Http1\EntityStream;

class HttpClient
{
    protected $pool;
    
    protected $keepAlive = true;
    
    protected $userAgent = 'KoolKode HTTP Client';
    
    protected $connectors;
    
    protected $pending;
    
    public function __construct(HttpConnector ...$connectors)
    {
        $this->connectors = $connectors ?: [
            new Connector()
        ];
        
        $this->pool = new ConnectionPool($this->getProtocols());
        $this->pending = new \SplObjectStorage();
    }
        
    public function shutdown(): Awaitable
    {
        $close = [
            $this->pool->shutdown()
        ];
        
        foreach ($this->connectors as $connector) {
            $close[] = $connector->shutdown();
        }
        
        try {
            foreach ($this->pending as $pending) {
                if ($pending instanceof Awaitable) {
                    $pending->cancel(new \RuntimeException('HTTP client shutdown'));
                }
            }
        } finally {
            $this->pending = new \SplObjectStorage();
        }
        
        return new AwaitPending($close);
    }
    
    public function getProtocols(): array
    {
        return \array_unique(\array_merge(...\array_map(function (HttpConnector $connector) {
            return $connector->getProtocols();
        }, $this->connectors)));
    }
    
    public function setKeepAlive(bool $keepAlive)
    {
        $this->keepAlive = $keepAlive;
    }
    
    public function send(HttpRequest $request): Awaitable
    {
        return new Coroutine(function () use ($request) {
            if (!$request->hasHeader('User-Agent')) {
                $request = $request->withHeader('User-Agent', $this->userAgent);
            }
            
            $conn = yield $this->pool->connect($uri = $request->getUri(), $this->keepAlive);
            
            $meta = $conn->getMetadata();
            $alpn = \trim($meta['crypto']['alpn_protocol'] ?? '');
            $connector = null;
            
            foreach ($this->connectors as $candidate) {
                if ($candidate->isSupported($alpn, $meta)) {
                    $connector = $candidate;
                    
                    break;
                }
            }
            
            if ($connector === null) {
                throw new \RuntimeException(\sprintf('No HTTP connector could handle negotiated ALPN protocol "%s"', $alpn));
            }
            
            $this->pending->attach($pending = $connector->send($conn, $request, $this->keepAlive));
            
            try {
                $response = yield $pending;
            } finally {
                $this->pending->detach($pending);
            }
            
            if ($this->keepAlive) {
                $stream = (yield $response->getBody()->getReadableStream());
                
                if ($stream instanceof EntityStream) {
                    $stream->getAwaitable()->when(function () use ($uri, $conn) {
                        $this->pool->release($uri, $conn);
                    });
                } else {
                    $this->pool->release($uri, $conn);
                }
            }
            
            return $response;
        });
    }
}
