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
use KoolKode\Async\Http\Http1\Driver;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketServerFactory;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\StreamClosedException;

class HttpEndpoint
{
    protected $factory;
    
    protected $server;
    
    protected $drivers = [];

    protected $http1;
    
    public function __construct(string $peer = '0.0.0.0:0', string $peerName = 'localhost')
    {
        $this->factory = new SocketServerFactory($peer);
        $this->factory->setPeerName($peerName);
        
        $this->http1 = new Driver();
    }

    public function isEncrypted(): bool
    {
        return $this->factory->getOption('ssl', 'local_cert') !== null;
    }
    
    public function setCertificate(string $file, bool $allowSelfSigned = false, string $password = null)
    {
        $this->factory->setCertificate($file, $allowSelfSigned, $password);
    }
    
    public function addDriver(HttpDriver $driver)
    {
        $this->drivers[] = $driver;
    }

    public function listen(): Awaitable
    {
        return new Coroutine(function () {
            $factory = clone $this->factory;
            
            if ($factory->isEncrypted() && Socket::isAlpnSupported()) {
                $protocols = [];
                
                foreach ($this->drivers as $driver) {
                    $protocols = \array_merge($protocols, $driver->getProtocols());
                }
                
                $protocols = \array_unique(\array_merge($protocols, $this->http1->getProtocols()));
                $factory->setOption('ssl', 'alpn_protocols', implode(',', $protocols));
            }
            
            $this->server = yield $factory->createSocketServer();
            
            return new HttpServer($this, $this->server, new Coroutine($this->runServer()));
        });
    }

    protected function runServer(): \Generator
    {
        $pending = new \SplObjectStorage();
        
        try {
            yield $this->server->listen(function (SocketStream $socket) use ($pending) {
                if ($this->isEncrypted()) {
                    $alpn = \trim($socket->getMetadata()['crypto']['alpn_protocol'] ?? '');
                } else {
                    $alpn = '';
                }
                
                $request = null;
                
                foreach ($this->drivers as $driver) {
                    if (\in_array($alpn, $driver->getProtocols(), true)) {
                        $request = $driver->handleConnection($socket);
                        
                        break;
                    }
                }
                
                if ($request === null) {
                    $request = $this->http1->handleConnection($socket);
                }
                
                $pending->attach($request);
                
                try {
                    yield $request;
                } finally {
                    $pending->detach($request);
                }
            });
        } finally {
            $this->server = null;
            
            foreach ($pending as $request) {
                $request->cancel(new StreamClosedException('HTTP server stopped'));
            }
        }
    }
}
