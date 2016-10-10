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

namespace KoolKode\Async\Http;

use KoolKode\Async\Awaitable;
use KoolKode\Async\AwaitPending;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Deferred;
use KoolKode\Async\DNS\Address;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketFactory;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Success;

class ConnectionPool
{
    protected $conns = [];
    
    protected $protocols;
    
    public function __construct(array $protocols = [])
    {
        $this->protocols = $protocols;
    }
    
    public function setProtocols(array $protocols)
    {
        $this->protocols = $protocols;
    }

    public function shutdown(): Awaitable
    {
        $tasks = [];
        $e = new StreamClosedException('Http connection closed');
        
        foreach ($this->conns as $q) {
            if (isset($q[1])) {
                while (!$q[1]->isEmpty()) {
                    $q[1]->dequeue()->cancel($e);
                }
            }
            
            if (isset($q[0])) {
                while (!$q[0]->isEmpty()) {
                    $tasks[] = $q[0]->dequeue()->close();
                }
            }
        }
        
        if ($tasks) {
            return new AwaitPending($tasks);
        }
        
        return new Success(null);
    }
    
    public function connect(Uri $uri, bool $keepAlive): Awaitable
    {
        $host = $uri->getHost();
        $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
        
        if ($keepAlive && isset($this->conns[$key])) {
            if (!$this->conns[$key][0]->isEmpty()) {
                return new Success($this->conns[$key][0]->dequeue());
            }
            
            if (empty($this->conns[$key][1])) {
                $this->conns[$key][1] = new \SplQueue();
            }
            
            $defer = new Deferred();
            
            $this->conns[$key][1]->enqueue($defer);
            
            return $defer;
        }
        
        if (Address::isResolved($host)) {
            $factory = new SocketFactory(new Address($host) . ':' . $uri->getPort(), 'tcp');
        } else {
            $factory = new SocketFactory($uri->getHostWithPort(true), 'tcp');
        }
        
        if ($this->protocols && Socket::isAlpnSupported()) {
            $factory->setOption('ssl', 'alpn_protocols', \implode(',', $this->protocols));
        }
        
        return new Coroutine(function () use ($key, $factory, $uri) {
            return yield $factory->createSocketStream(5, $uri->getScheme() === 'https');
        });
    }
    
    public function release(Uri $uri, SocketStream $conn)
    {
        $socket = $conn->getSocket();
        
        if (\is_resource($socket) && !\feof($socket)) {
            $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
            
            if (empty($this->conns[$key])) {
                $this->conns[$key] = [];
            }
            
            if (empty($this->conns[$key][0])) {
                $this->conns[$key][0] = new \SplQueue();
            }
            
            $this->conns[$key][0]->enqueue($conn);
        }
    }
}
