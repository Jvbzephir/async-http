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
use KoolKode\Async\Http\HttpDriver;
use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Http\Middleware\MiddlewareSupported;
use KoolKode\Async\Http\Responder\ResponderSupported;
use KoolKode\Async\Socket\SocketStream;

/**
 * Specialized HTTP endpoint being used by end-to-end testing.
 * 
 * @author Martin Schröder
 */
class HttpTestEndpoint
{
    use MiddlewareSupported;
    use ResponderSupported;
    
    protected $peerName;
    
    protected $encrypted;
    
    protected $drivers = [];
    
    protected $action;

    public function __construct(array $drivers, string $peerName = 'localhost', bool $encrypted = false)
    {
        $this->drivers = $drivers;
        $this->peerName = $peerName;
        $this->encrypted = $encrypted;
        
        \usort($this->drivers, function (HttpDriver $a, HttpDriver $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
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
        $port = $this->encrypted ? 443 : 80;
        
        $context = new HttpDriverContext('127.0.0.1:' . $port, $this->peerName, $this->encrypted, $this->middlewares, $this->responders);
        
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
