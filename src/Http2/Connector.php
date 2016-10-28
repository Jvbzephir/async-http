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
use KoolKode\Async\Deferred;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpConnectorContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Success;
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
    
    protected $connecting = [];
    
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
    public function getConnectorContext(Uri $uri): Awaitable
    {
        $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
        
        if (isset($this->connections[$key])) {
            if ($this->connections[$key]->isAlive()) {
                $context = new ConnectorContext();
                $context->connected = true;
                $context->conn = $this->connections[$key];
                
                return new Success($context);
            }
            
            unset($this->connections[$key]);
        }
        
        if (isset($this->connecting[$key])) {
            $defer = new Deferred();
            
            $this->connecting[$key]->enqueue($defer);
            
            return $defer;
        }
        
        $this->connecting[$key] = new \SplQueue();
        
        $context = new ConnectorContext(function ($context) use ($key) {
            if ($this->connecting[$key]->isEmpty()) {
                unset($this->connecting[$key]);
            } else {
                $this->connecting[$key]->dequeue()->resolve($context);
            }
        });
        
        return new Success($context);
    }

    /**
     * {@inheritdoc}
     */
    public function send(HttpConnectorContext $context, HttpRequest $request): Awaitable
    {
        if (!$context instanceof ConnectorContext) {
            throw new \InvalidArgumentException('Invalid connector context passed');
        }
        
        return new Coroutine(function () use ($context, $request) {
            $uri = $request->getUri();
            $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
            
            if ($context->conn) {
                $conn = $context->conn;
            } else {
                $conn = new Connection($context->socket, new HPack($this->hpackContext), $this->logger);
                
                yield $conn->performClientHandshake();
                
                $this->connections[$key] = $conn;
            }
            
            if ($this->logger) {
                $this->logger->info(\sprintf('%s %s HTTP/%s', $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion()));
            }
            
            $request = $request->withHeader('Date', \gmdate(Http::DATE_RFC1123));
            
            $response = yield $conn->openStream()->sendRequest($request);
            
            if ($this->logger) {
                $reason = \rtrim(' ' . $response->getReasonPhrase());
                
                if ($reason === '') {
                    $reason = \rtrim(' ' . Http::getReason($response->getStatusCode()));
                }
                
                $this->logger->info(\sprintf('HTTP/%s %03u%s', $response->getProtocolVersion(), $response->getStatusCode(), $reason));
            }
            
            if (isset($this->connecting[$key])) {
                $context->connected = true;
                $context->conn = $conn;
                
                $context->dispose();
            }
            
            return $response;
        });
    }
}
