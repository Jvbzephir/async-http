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
use KoolKode\Async\Http\Http1\Http1Driver;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketServerFactory;
use KoolKode\Async\Socket\SocketStream;

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
        
        $this->http1 = new Http1Driver();
    }

    public function isEncrypted(): bool
    {
        return $this->factory->getOption('ssl', 'local_cert') !== null;
    }
    
    public function setCertificate(string $file, bool $allowSelfSigned = false, string $password = null)
    {
        $this->factory->setCertificate($file, $allowSelfSigned, $password);
    }

    public function listen(): Awaitable
    {
        return new Coroutine(function () {
            $factory = clone $this->factory;
            
            if ($factory->isEncrypted() && Socket::isAlpnSupported()) {
                $protocols = [];
                
                $protocols = \array_unique(\array_merge($protocols, $this->http1->getProtocols()));
                $factory->setOption('ssl', 'alpn_protocols', implode(',', $protocols));
            }
            
            $this->server = yield $factory->createSocketServer();
            
            return new HttpServer($this, $this->server, new Coroutine($this->runServer()));
        });
    }

    protected function runServer(): \Generator
    {
        try {
            yield $this->server->listen(function (SocketStream $socket) {
                if ($this->isEncrypted()) {
                    $alpn = \trim($socket->getMetadata()['crypto']['alpn_protocol'] ?? '');
                } else {
                    $alpn = '';
                }
                
                yield from $this->http1->handleConnection($socket);
            });
        } finally {
            $this->server = NULL;
        }
    }
}
