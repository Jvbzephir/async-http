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
use KoolKode\Async\Failure;
use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Socket\SocketStream;

/**
 * Specialized HTTP endpoint being used by end-to-end testing.
 * 
 * @author Martin Schröder
 */
class HttpTestEndpoint
{
    protected $peerName;
    
    protected $encrypted;
    
    protected $drivers = [];
    
    protected $middleware;
    
    protected $action;

    public function __construct(array $drivers, string $peerName = 'localhost', bool $encrypted = false)
    {
        $this->peerName = $peerName;
        $this->encrypted = $encrypted;
        $this->middleware = new \SplPriorityQueue();
        
        foreach ($drivers as $driver) {
            $this->drivers[] = $driver;
        }
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function getDrivers(): array
    {
        return $this->drivers;
    }
    
    public function setAction(callable $action)
    {
        $this->action = $action;
    }

    public function accept(SocketStream $socket, string $alpn = ''): Awaitable
    {
        $context = new HttpDriverContext($this->peerName, $this->encrypted, $this->middleware);
        
        if ($this->encrypted) {
            foreach ($this->drivers as $driver) {
                if (\in_array($alpn, $driver->getProtocols(), true)) {
                    return $driver->handleConnection($context, $socket, $this->action ?? function () {});
                }
            }
        }
        
        foreach ($this->drivers as $driver) {
            if (\in_array('http/1.1', $driver->getProtocols(), true)) {
                return $driver->handleConnection($context, $socket, $this->action ?? function () {});
            }
        }
        
        return new Failure(new \RuntimeException('No HTTP driver found'));
    }
}
