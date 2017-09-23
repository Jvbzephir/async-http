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

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Context;
use KoolKode\Async\Deferred;
use KoolKode\Async\Promise;
use KoolKode\Async\Success;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Socket\ClientEncryption;
use KoolKode\Async\Socket\ClientFactory;

class ConnectionManager
{
    protected $concurrency;

    protected $size = 0;

    protected $connections;

    protected $connecting;

    public function __construct(int $concurrency, ?ClientConnection $conn = null)
    {
        $this->concurrency = $concurrency;
        
        $this->connections = new \SplQueue();
        $this->connecting = new \SplQueue();
        
        if ($conn) {
            $this->size = 1;
            $this->connections->enqueue($conn);
        }
    }

    public function aquire(Context $context, Uri $uri, array $protocols): Promise
    {
        if (!$this->connections->isEmpty()) {
            return new Success($context, $this->connections->dequeue());
        }
        
        if ($this->size >= $this->concurrency) {
            $this->connecting->enqueue($defer = new Deferred($context));
            
            return $defer->promise();
        }
        
        $this->size++;
        
        return $context->task($this->connect($context, $uri, $protocols));
    }

    public function release(ClientConnection $conn, bool $close = false): void
    {
        if (--$conn->remaining < 1) {
            $close = true;
        }
        
        if ($close) {
            $this->size--;
            
            while (!$this->connecting->isEmpty()) {
                $this->connecting->dequeue()->resolve();
            }
        } elseif (!$this->connecting->isEmpty()) {
            $this->connecting->dequeue()->resolve($conn);
        } else {
            $this->connections->enqueue($conn);
        }
    }

    protected function connect(Context $context, Uri $uri, array $protocols): \Generator
    {
        try {
            $key = $uri->getScheme() . '://' . $uri->getHostWithPort(true);
            $tls = null;
            
            if ($uri->getScheme() == 'https') {
                $tls = new ClientEncryption();
                $tls = $tls->withPeerName($uri->getHostWithPort());
                $tls = $tls->withAlpnProtocols(...$protocols);
            }
            
            $factory = new ClientFactory('tcp://' . $uri->getHostWithPort(true), $tls);
            $conn = new ClientConnection($key, yield $factory->connect($context));
        } catch (\Throwable $e) {
            $this->size--;
            
            return null;
        }
        
        return $conn;
    }
}
