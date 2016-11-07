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

namespace KoolKode\Async\Http\Test;

use KoolKode\Async\Awaitable;
use KoolKode\Async\AwaitPending;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\HttpClient;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketStream;

/**
 * Specialized HTTP client being used in end-to-end testing.
 * 
 * @author Martin Schröder
 */
class HttpTestClient extends HttpClient
{
    protected $server;
    
    protected $socket;
    
    protected $workers;

    public function __construct(array $connectors, HttpTestEndpoint $server)
    {
        parent::__construct(...$connectors);
        
        $this->server = $server;        
        $this->workers = new \SplObjectStorage();
    }
    
    public function shutdown(): Awaitable
    {
        $close = [];
        
        foreach ($this->connectors as $connector) {
            $close[] = $connector->shutdown();
        }
        
        foreach ($this->workers as $worker) {
            foreach ($worker->cancel(new \RuntimeException()) as $awaitable) {
                $close[] = $awaitable;
            }
        }
        
        return new AwaitPending($close);
    }

    protected function connectSocket(Uri $uri): Awaitable
    {
        return new Coroutine(function () {
            list ($a, $b) = Socket::createPair();
            
            $this->socket = new SocketStream($b);
            
            return new SocketStream($a);
        });
    }

    protected function chooseConnector(HttpRequest $request, string $alpn, array $meta): HttpConnector
    {
        if ($this->server->isEncrypted()) {
            foreach ($this->connectors as $connector) {
                if ($connector->isRequestSupported($request)) {
                    continue;
                }
                
                foreach ($connector->getProtocols() as $protocol) {
                    foreach ($this->server->getDrivers() as $driver) {
                        foreach ($driver->getProtocols() as $alpn) {
                            if ($alpn == $protocol) {
                                $this->spawnWorker($alpn);
                                
                                return $connector;
                            }
                        }
                    }
                }
            }
        } else {
            foreach ($this->connectors as $connector) {
                if ($connector->isRequestSupported($request)) {
                    continue;
                }
                
                if (\in_array('http/1.1', $connector->getProtocols(), true)) {
                    $this->spawnWorker();
                    
                    return $connector;
                }
            }
        }
        
        throw new \RuntimeException('Missing HTTP connector');
    }
    
    protected function spawnWorker(string $alpn = '')
    {
        try {
            $this->workers->attach($worker = $this->server->accept($this->socket, $alpn));
        } finally {
            $this->socket = null;
        }
        
        $worker->when(function () use ($worker) {
            if ($this->workers->contains($worker)) {
                $this->workers->detach($worker);
            }
        });
    }
}
