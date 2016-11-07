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
use KoolKode\Async\Http\HttpDriver;
use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Http\Http1\Driver;
use KoolKode\Async\Http\Http1\UpgradeHandler;
use KoolKode\Async\Http\Http1\UpgradeResultHandler;
use KoolKode\Async\Socket\SocketStream;

class HttpTestEndpoint
{
    protected $peerName;
    
    protected $http1;

    protected $drivers = [];
    
    protected $middleware;

    public function __construct(string $peerName = 'localhost')
    {
        $this->peerName = $peerName;
        $this->middleware = new \SplPriorityQueue();
        
        $this->http1 = new Driver();
        $this->http1->setDebug(true);
    }

    public function addDriver(HttpDriver $driver)
    {
        $this->drivers[] = $driver;
        
        if ($driver instanceof UpgradeHandler) {
            $this->http1->addUpgradeHandler($driver);
        }
    }

    public function addUpgradeHandler(UpgradeHandler $handler)
    {
        $this->http1->addUpgradeHandler($handler);
    }

    public function addUpgradeResultHandler(UpgradeResultHandler $handler)
    {
        $this->http1->addUpgradeResultHandler($handler);
    }

    public function accept(SocketStream $socket, callable $action, string $alpn = ''): Awaitable
    {
        $context = new HttpDriverContext($this->peerName, false, $this->middleware);
        
        foreach ($this->drivers as $driver) {
            if (\in_array($alpn, $driver->getProtocols(), true)) {
                return $driver->handleConnection($context, $socket, $action);
            }
        }
        
        return $this->http1->handleConnection($context, $socket, $action);
    }
}
