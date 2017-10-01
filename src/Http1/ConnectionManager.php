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

// TODO: Use event loop to implement connection timeouts and cleanup.

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Context;
use KoolKode\Async\Promise;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Loop\Loop;

class ConnectionManager implements \Countable
{    
    protected $concurrency;

    protected $loop;

    protected $pools = [];

    public function __construct(Loop $loop, int $concurrency = 8)
    {
        $this->loop = $loop;
        $this->concurrency = $concurrency;
    }
    
    public function count()
    {
        return \array_sum(\array_map('count', $this->pools));
    }

    public function isConnected(string $key): bool
    {
        return isset($this->pools[$key]);
    }

    public function aquire(Context $context, string $key, Uri $uri, array $protocols): Promise
    {
        if (empty($this->pools[$key])) {
            $this->pools[$key] = new ConnectionPool($this->concurrency);
        }
        
        return $this->pools[$key]->aquire($context, $key, $uri, $protocols);
    }

    public function release(ClientConnection $conn, bool $close = false): void
    {
        if (empty($this->pools[$conn->key])) {
            $this->pools[$conn->key] = new ConnectionPool($this->concurrency);
        }
        
        $this->pools[$conn->key]->release($conn, $close);
    }

    public function checkin(ClientConnection $conn): void
    {
        if (empty($this->pools[$conn->key])) {
            $this->pools[$conn->key] = new ConnectionPool($this->concurrency);
        }
        
        $this->pools[$conn->key]->checkin($conn);
    }
    
    public function checkout(ClientConnection $conn): void
    {
        if (empty($this->pools[$conn->key])) {
            $this->pools[$conn->key] = new ConnectionPool($this->concurrency);
        }
        
        $this->pools[$conn->key]->checkout($conn);
    }
}
