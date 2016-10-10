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

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Awaitable;
use KoolKode\Async\AwaitPending;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Stream\DuplexStream;

class Connector implements HttpConnector
{
    protected $connections;
    
    protected $hpackContext;
    
    public function __construct(HPackContext $hpackContext = null)
    {
        $this->connections = new \SplObjectStorage();
        $this->hpackContext = $hpackContext ?? new HPackContext();
    }
    
    public function getProtocols(): array
    {
        return [
            'h2'
        ];
    }

    public function isSupported(string $protocol, array $meta = []): bool
    {
        return $protocol === 'h2';
    }

    public function shutdown(): Awaitable
    {
        $tasks = [];
        
        try {
            foreach ($this->connections as $conn) {
                $tasks[] = $this->connections[$conn]->shutdown();
            }
        } finally {
            $this->connections = new \SplObjectStorage();
        }
        
        return new AwaitPending($tasks);
    }

    public function send(DuplexStream $stream, HttpRequest $request, bool $keepAlive = true): Awaitable
    {
        return new Coroutine(function () use ($stream, $request) {
            if ($this->connections->contains($stream)) {
                $conn = $this->connections[$stream];
            } else {
                $this->connections->attach($stream, $conn = new Connection($stream, new HPack($this->hpackContext)));
                
                yield $conn->startClient();
            }
            
            $stream = $conn->openStream();
            
            yield $stream->sendRequest($request);
            
            return new \KoolKode\Async\Http\HttpResponse();
        });
    }
}
