<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Awaitable;
use KoolKode\Async\AwaitPending;
use KoolKode\Async\Deferred;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Success;

/**
 * Manages HTTP/1 client connections.
 * 
 * @author Martin Schröder
 */
class ConnectionManager
{
    protected $limit;
    
    protected $maxLifetime;
    
    protected $max;
    
    protected $count = [];
    
    protected $conns = [];
    
    protected $connecting = [];

    /**
     * Create a new HTTP/1 client connection manager.
     * 
     * @param int $limit Max number of connection per host (including port and scheme).
     * @param int $maxLifetime Max idle time of pooled connections (in seconds).
     * @param int $max Maximum number of HTTP requests to be sent over a connection.
     */
    public function __construct(int $limit = 8, int $maxLifetime = 30, int $max = 100)
    {
        $this->limit = $limit;
        $this->maxLifetime = $maxLifetime;
        $this->max = $max;
    }
    
    public function getMaxLifetime(): int
    {
        return $this->maxLifetime;
    }
    
    public function getMaxRequests(): int
    {
        return $this->max;
    }

    public function shutdown(): Awaitable
    {
        $tasks = [];
        
        try {
            foreach ($this->connecting as $conn) {
                foreach ($conn as $defer) {
                    $defer->fail(new \RuntimeException('HTTP connection manager shut down'));
                }
            }
            
            foreach ($this->conns as $conns) {
                foreach ($conns as $context) {
                    $tasks[] = $context->socket->close();
                }
            }
        } finally {
            $this->count = [];
            $this->conns = [];
            $this->connecting = [];
        }
        
        return new AwaitPending($tasks);
    }
    
    public function getConnectionCount(Uri $uri)
    {
        return $this->count[\sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true))] ?? 0;
    }

    public function getConnection(Uri $uri): Awaitable
    {
        $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
        $time = \time();
        
        if (isset($this->conns[$key])) {
            while (!$this->conns[$key]->isEmpty()) {
                $context = $this->conns[$key]->dequeue();
                
                if ($context->expires > $time && $context->socket->isAlive()) {
                    return new Success($context);
                }
                
                $this->connectionDisposed($key);
            }
        }
        
        if (isset($this->count[$key]) && $this->count[$key] >= $this->limit) {
            return $this->connecting[$key][] = new Deferred();
        }
        
        if (isset($this->count[$key])) {
            $this->count[$key]++;
        } else {
            $this->count[$key] = 1;
        }
        
        return new Success($this->createContext($key));
    }

    public function releaseConnection(Uri $uri, ConnectorContext $context, int $ttl = null, int $remaining = null)
    {
        $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
        
        if ($ttl === null) {
            $ttl = $this->maxLifetime;
        } else {
            $ttl = \min($ttl, $this->maxLifetime);
        }
        
        if ($remaining === null) {
            $remaining = $this->max;
        } else {
            $remaining = \min($remaining, $this->max);
        }
        
        if ($remaining > 0 && $ttl > 0 && $context->socket->isAlive()) {
            $context->connected = true;
            
            if (empty($this->conns[$key])) {
                $this->conns[$key] = new \SplQueue();
            }
            
            if (!empty($this->connecting[$key])) {
                $defer = \array_shift($this->connecting[$key]);
                
                return $defer->resolve($context);
            }
            
            $context->expires = \time() + $ttl;
            $context->remaining = $remaining;
            
            $this->conns[$key]->enqueue($context);
            
            return;
        }
        
        $context->socket->close();
        
        $this->connectionDisposed($key);
    }

    protected function connectionDisposed(string $key)
    {
        if (!empty($this->connecting[$key])) {
            $defer = \array_shift($this->connecting[$key]);
            
            return $defer->resolve($this->createContext($key));
        }
        
        $this->count[$key]--;
    }

    protected function createContext(string $key): ConnectorContext
    {
        $context = new ConnectorContext(function () use ($key) {
            $this->connectionDisposed($key);
        });
        
        $context->remaining = $this->max;
        
        return $context;
    }
}
