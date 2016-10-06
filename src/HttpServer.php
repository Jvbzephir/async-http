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

    public function __construct(HttpEndpoint $endpoint, SocketServer $server, Awaitable $runner)
    {
        $this->endpoint = $endpoint;
        $this->server = $server;
        $this->runner = $runner;
    }

    public function isEncrypted(): bool
    {
        return $this->server->isEncrypted();
    }
    
    public function createSocketFactory(): SocketFactory
    {
        return $this->server->createSocketFactory();
    }

    public function stop(\Throwable $e = null)
    {
        if ($this->runner) {
            try {
                $this->runner->cancel($e ?? new \RuntimeException('HTTP server stopped'));
            } finally {
                $this->runner = null;
            }
        }
    }
}
