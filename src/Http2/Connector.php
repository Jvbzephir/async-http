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
use KoolKode\Async\Failure;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpConnectorContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Uri;

class Connector implements HttpConnector
{
    protected $connections = [];
    
    protected $hpackContext;
    
    public function __construct(HPackContext $hpackContext = null)
    {
        $this->hpackContext = $hpackContext ?? new HPackContext();
    }
    
    /**
     * {@inheritdoc}
     */
    public function getProtocols(): array
    {
        return [
            'h2'
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function isSupported(string $protocol, array $meta = []): bool
    {
        return $protocol === 'h2';
    }

    /**
     * {@inheritdoc}
     */
    public function shutdown(): Awaitable
    {
        $tasks = [];
        
        try {
            foreach ($this->connections as $conn) {
                $tasks[] = $conn->shutdown();
            }
        } finally {
            $this->connections = [];
        }
        
        return new AwaitPending($tasks);
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectorContext(Uri $uri): HttpConnectorContext
    {
        $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
        $context = new ConnectorContext();
        
        if (isset($this->connections[$key])) {
            $context->connected = true;
            $context->conn = $this->connections[$key];
        }
        
        return $context;
    }

    /**
     * {@inheritdoc}
     */
    public function send(HttpConnectorContext $context, HttpRequest $request): Awaitable
    {
        return new Coroutine(function () use ($context, $request) {
            if ($context instanceof ConnectorContext && $context->conn) {
                $conn = $context->conn;
            } else {
                $conn = yield Connection::connectClient($context->stream, new HPack($this->hpackContext));
                $uri = $request->getUri();
                
                $this->connections[\sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true))] = $conn;
            }
            
            return yield $conn->openStream()->sendRequest($request);
        });
    }
}
