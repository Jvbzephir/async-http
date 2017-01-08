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
use KoolKode\Async\Socket\SocketFactory;
use KoolKode\Async\Socket\SocketServer;

class HttpServer
{
    protected $endpoint;
    
    protected $server;
    
    protected $runner;
    
    protected $socketFactory;

    public function __construct(HttpEndpoint $endpoint, SocketServer $server, Awaitable $runner)
    {
        $this->endpoint = $endpoint;
        $this->server = $server;
        $this->runner = $runner;
        
        $this->socketFactory = $server->createSocketFactory();
    }

    public function getPeer(): string
    {
        return $this->socketFactory->getPeer();
    }

    public function getPeerName(): string
    {
        return $this->socketFactory->getPeerName();
    }

    public function getPort(): int
    {
        $parts = \explode(':', $this->socketFactory->getPeer());
        
        return (int) \array_pop($parts);
    }
    
    public function isEncrypted(): bool
    {
        return $this->server->isEncrypted();
    }
    
    public function getBaseUri(): Uri
    {
        return Uri::parse(\sprintf('%s://%s/', $this->server->isEncrypted() ? 'https' : 'http', $this->socketFactory->getPeer()));
    }
    
    public function createSocketFactory(): SocketFactory
    {
        return $this->server->createSocketFactory();
    }

    public function stop(\Throwable $e = null)
    {
        if ($this->runner) {
            try {
                $this->runner->cancel('HTTP server stopped', $e);
            } finally {
                $this->runner = null;
            }
        }
    }
}
