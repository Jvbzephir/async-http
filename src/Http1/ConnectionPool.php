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
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Socket\SocketStream;

class ConnectionPool
{
    protected $conns = [];
    
    public function shutdown(): Awaitable
    {
        $close = [];
        
        foreach ($this->conns as $conn) {
            while (!$conn->isEmpty()) {
                $close[] = $conn->dequeue()->close();
            }
        }
        
        return new AwaitPending($close);
    }
    
    public function getConnection(Uri $uri)
    {
        $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
        
        if (isset($this->conns[$key])) {
            if (!$this->conns[$key]->isEmpty()) {
                return $this->conns[$key]->dequeue();
            }
        }
    }
    
    public function release(Uri $uri, SocketStream $conn)
    {
        $socket = $conn->getSocket();
        
        if (\is_resource($socket) && !\feof($socket)) {
            $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
            
            if (empty($this->conns[$key])) {
                $this->conns[$key] = new \SplQueue();
            }
            
            $this->conns[$key]->enqueue($conn);
        }
    }
}
