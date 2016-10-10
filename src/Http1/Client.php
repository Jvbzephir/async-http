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
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\HttpRequest;

class Client 
{
    protected $connector;
    
    protected $pool;
    
    protected $userAgent = 'KoolKode HTTP Client';

    public function __construct(Connector $connector = null, ConnectionPool $pool = null)
    {
        $this->connector = $connector ?? new Connector();
        $this->pool = $pool ?? new ConnectionPool();
    }

    public function shutdown()
    {
        $this->pool->shutdown();
    }

    public function send(HttpRequest $request): Awaitable
    {
        return new Coroutine(function () use ($request) {
            $conn = yield $this->pool->connect($uri = $request->getUri(), $this->connector->isKeepAliveSupported());
            
            $request = $request->withHeader('User-Agent', $this->userAgent);
            
            $response = yield $this->connector->send($conn, $request);
            
            if ($this->connector->isKeepAliveSupported()) {
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
