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

class HttpClient
{
    protected $pool;
    
    protected $keepAlive = true;
    
    protected $userAgent = 'KoolKode HTTP Client';
    
    protected $connectors;
    
    public function __construct(HttpConnector ...$connectors)
    {
        $this->connectors = $connectors ?: [
            new Connector()
        ];
        
        $this->pool = new ConnectionPool();
    }
        
    public function shutdown(): Awaitable
    {
        $close = [
            $this->pool->shutdown()
        ];
        
        // TODO: Dispose running requests.
        
        return new AwaitPending($close);
    }
    
    public function setKeepAlive(bool $keepAlive)
    {
        $this->keepAlive = $keepAlive;
    }
    
    public function send(HttpRequest $request): Awaitable
    {
        return new Coroutine(function () use ($request) {
            $request = $request->withHeader('User-Agent', $this->userAgent);
            
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
            
            $response = yield $connector->send($conn, $request, $this->keepAlive);
            
            if ($this->keepAlive) {
                $stream = (yield $response->getBody()->getReadableStream());
                
                if ($stream instanceof BodyStream) {
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
