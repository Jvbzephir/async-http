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
use KoolKode\Async\Http\Http1\Driver as Http1Driver;
use KoolKode\Async\Http\Middleware\MiddlewareSupported;
use KoolKode\Async\Http\Responder\ResponderSupported;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketServerFactory;
use KoolKode\Async\Socket\SocketStream;

class HttpEndpoint implements Endpoint
{
    use MiddlewareSupported;
    use ResponderSupported;
    
    protected $factory;
    
    protected $server;
    
    protected $drivers = [];
    
    protected $proxySettings;
    
    public function __construct(string $peer = '0.0.0.0:0', string $peerName = 'localhost', HttpDriver ...$drivers)
    {
        $this->factory = new SocketServerFactory($peer);
        $this->factory->setPeerName($peerName);
        
        if (empty($drivers)) {
            $this->drivers[] = new Http1Driver();
        } else {
            foreach ($drivers as $driver) {
                $this->drivers[] = $driver;
            }
            
            \usort($this->drivers, function (HttpDriver $a, HttpDriver $b) {
                return $b->getPriority() <=> $a->getPriority();
            });
        }
    }
    
    public function setCertificate(string $file, bool $allowSelfSigned = false, string $password = null)
    {
        $this->factory->setCertificate($file, $allowSelfSigned, $password);
    }
    
    public function getSocketServerFactory(): SocketServerFactory
    {
        return $this->factory;
    }

    public function setProxySettings(ReverseProxySettings $proxy)
    {
        $this->proxySettings = $proxy;
    }

    /**
     * {@inheritdoc}
     */
    public function listen(callable $action): Awaitable
    {
        return new Coroutine(function () use ($action) {
            $factory = clone $this->factory;
            $factory->setTcpNoDelay(true);
            
            if ($factory->isEncrypted() && Socket::isAlpnSupported()) {
                $protocols = [];
                
                foreach ($this->drivers as $driver) {
                    foreach ($driver->getProtocols() as $protocol) {
                        $protocols[$protocol] = true;
                    }
                }
                
                $factory->setOption('ssl', 'alpn_protocols', implode(',', \array_keys($protocols)));
            }
            
            $this->server = yield $factory->createSocketServer();
            
            $port = $this->server->getPort();
            $peer = $this->server->getAddress() . ($port ? (':' . $port) : '');
            
            $context = new HttpDriverContext($peer, $factory->getPeerName(), $factory->isEncrypted(), $this->middlewares, $this->responders, $this->proxySettings);
            
            return new HttpServer($this, $this->server, new Coroutine($this->runServer($context, $action)));
        });
    }

    protected function runServer(HttpDriverContext $context, callable $action): \Generator
    {
        $pending = new \SplObjectStorage();
        
        try {
            yield $this->server->listen(function (SocketStream $socket) use ($pending, $context, $action) {
                if ($socket->isEncrypted()) {
                    $alpn = \trim($socket->getMetadata()['crypto']['alpn_protocol'] ?? '');
                } else {
                    $alpn = '';
                }
                
                $request = null;
                
                foreach ($this->drivers as $driver) {
                    if (\in_array($alpn, $driver->getProtocols(), true)) {
                        $request = $driver->handleConnection($context, $socket, $action);
                        
                        break;
                    }
                }
                
                if ($request === null) {
                    foreach ($this->drivers as $driver) {
                        if ($driver instanceof Http1Driver) {
                            $request = $driver->handleConnection($context, $socket, $action);
                            
                            break;
                        }
                    }
                }
                
                if ($request === null) {
                    throw new \RuntimeException(\sprintf('No suitable HTTP driver registered to handle protocol: "%s"', $alpn));
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
                $request->cancel('HTTP server stopped');
            }
        }
    }
}
