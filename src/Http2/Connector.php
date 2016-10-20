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
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpConnectorContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Uri;
use Psr\Log\LoggerInterface;

/**
 * Implements the HTTP/2 protocol on the client side.
 * 
 * @author Martin Schröder
 */
class Connector implements HttpConnector
{
    protected $hpackContext;
    
    protected $logger;
 
    protected $connections = [];
    
    public function __construct(HPackContext $hpackContext = null, LoggerInterface $logger = null)
    {
        $this->hpackContext = $hpackContext ?? HPackContext::createClientContext();
        $this->logger = $logger;
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
            if ($this->connections[$key]->isAlive()) {
                $context->connected = true;
                $context->conn = $this->connections[$key];
            } else {
                unset($this->connections[$key]);
            }
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
                $conn = new Connection($context->socket, new HPack($this->hpackContext), $this->logger);
                
                yield $conn->performClientHandshake();
                
                $uri = $request->getUri();
                $this->connections[\sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true))] = $conn;
            }
            
            if ($this->logger) {
                $this->logger->info(\sprintf('%s %s HTTP/%s', $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion()));
            }
            
            $response = yield $conn->openStream()->sendRequest($request);
            
            if ($this->logger) {
                $reason = \rtrim(' ' . $response->getReasonPhrase());
            
                if ($reason === '') {
                    $reason = \rtrim(' ' . Http::getReason($response->getStatusCode()));
                }
            
                $this->logger->info(\sprintf('HTTP/%s %03u%s', $response->getProtocolVersion(), $response->getStatusCode(), $reason));
            }
            
            return $response;
        });
    }
}
