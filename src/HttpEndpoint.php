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
use KoolKode\Async\Http\Http1\UpgradeHandler;
use KoolKode\Async\Http\Http1\UpgradeResultHandler;
use KoolKode\Async\Http\Middleware\MiddlewareSupported;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketServerFactory;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\StreamClosedException;
use Psr\Log\LoggerInterface;

class HttpEndpoint
{
    use MiddlewareSupported;
    
    protected $factory;
    
    protected $server;
    
    protected $drivers = [];
    
    protected $http1;
    
    protected $logger;
    
    protected $proxySettings;
    
    public function __construct(string $peer = '0.0.0.0:0', string $peerName = 'localhost', LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        
        $this->factory = new SocketServerFactory($peer);
        $this->factory->setPeerName($peerName);
        
        $this->http1 = new Driver(null, $logger);
        
        $this->proxySettings = new ProxySettings();
    }
    
    public function setCertificate(string $file, bool $allowSelfSigned = false, string $password = null)
    {
        $this->factory->setCertificate($file, $allowSelfSigned, $password);
    }
    
    public function getSocketServerFactory(): SocketServerFactory
    {
        return $this->factory;
    }
    
    public function addDriver(HttpDriver $driver)
    {
        $this->drivers[] = $driver;
        
        if ($driver instanceof UpgradeHandler) {
            $this->http1->addUpgradeHandler($driver);
        }
        
        \usort($this->drivers, function (HttpDriver $a, HttpDriver $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }

    public function addUpgradeHandler(UpgradeHandler $handler)
    {
        $this->http1->addUpgradeHandler($handler);
    }
    
    public function addUpgradeResultHandler(UpgradeResultHandler $handler)
    {
        $this->http1->addUpgradeResultHandler($handler);
    }

    public function getProxySettings(): ProxySettings
    {
        return $this->proxySettings;
    }

    public function listen(callable $action): Awaitable
    {
        return new Coroutine(function () use ($action) {
            $factory = clone $this->factory;
            $factory->setTcpNoDelay(true);
            
            if ($factory->isEncrypted() && Socket::isAlpnSupported()) {
                $protocols = [];
                
                foreach ($this->drivers as $driver) {
                    $protocols = \array_merge($protocols, $driver->getProtocols());
                }
                
                $protocols = \array_unique(\array_merge($protocols, $this->http1->getProtocols()));
                $factory->setOption('ssl', 'alpn_protocols', implode(',', $protocols));
            }
            
            $this->server = yield $factory->createSocketServer();
            
            $context = new HttpDriverContext($factory->getPeerName(), $factory->isEncrypted(), $this->middlewares, $this->proxySettings);
            
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
                    $request = $this->http1->handleConnection($context, $socket, $action);
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
