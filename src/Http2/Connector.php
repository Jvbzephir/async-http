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
use KoolKode\Async\Coroutine;
use KoolKode\Async\Deferred;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpConnectorContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Success;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Implements the HTTP/2 protocol on the client side.
 * 
 * @author Martin Schröder
 */
class Connector implements HttpConnector, LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    protected $hpackContext;
    
    protected $connections = [];
    
    protected $connecting = [];
    
    public function __construct(HPackContext $hpackContext = null)
    {
        $this->hpackContext = $hpackContext ?? HPackContext::createClientContext();
    }
    
    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 20;
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
    public function isRequestSupported(HttpRequest $request): bool
    {
        return (float) $request->getProtocolVersion() >= 2.0;
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
    public function shutdown()
    {
        try {
            foreach ($this->connections as $conn) {
                $conn->shutdown();
            }
        } finally {
            $this->connections = [];
        }
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
                $conn = new Connection($context->socket, new HPack($this->hpackContext));
                
                if ($this->logger) {
                    $conn->setLogger($this->logger);
                }
                
                yield $conn->performClientHandshake();
                
                $this->connections[$key] = $conn;
            }
            
            $request = $request->withProtocolVersion('2.0');
            $request = $request->withHeader('Date', \gmdate(Http::DATE_RFC1123));
            
            $sent = 0;
            $response = yield $conn->openStream()->sendRequest($request, $sent);
            
            if ($this->logger) {
                $this->logger->info('{ip} "{method} {target} HTTP/{protocol}" {status} {size}', [
                    'ip' => $request->getClientAddress(),
                    'method' => $request->getMethod(),
                    'target' => $request->getRequestTarget(),
                    'protocol' => $response->getProtocolVersion(),
                    'status' => $response->getStatusCode(),
                    'size' => $sent ?: '-'
                ]);
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
