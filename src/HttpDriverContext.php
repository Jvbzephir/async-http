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

namespace KoolKode\Async\Http;

/**
 * Passes contextual options to an HTTP driver.
 * 
 * @author Martin Schröder
 */
class HttpDriverContext
{
    /**
     * Peer used by HTTP endpoint.
     * 
     * @var string
     */
    protected $peer;
    
    /**
     * Server peer name.
     * 
     * @var string
     */
    protected $peerName;
    
    /**
     * Is the HTTP endpoint encrypted using SSL / TSL.
     * 
     * @var bool
     */
    protected $encrypted;
    
    /**
     * Registered prioritized HTTP middleware.
     * 
     * @var array
     */
    protected $middlewares;
    
    /**
     * Registered prioritized HTTP responders.
     * 
     * @var array
     */
    protected $responders;
    
    /**
     * HTTP proxy mode settings.
     * 
     * @var ReverseProxySettings
     */
    protected $proxy;
    
    /**
     * Create a new HTTP server / driver context.
     * 
     * @param string $peerName
     * @param bool $encrypted
     * @param array $middlewares
     */
    public function __construct(string $peer = '127.0.0.1', string $peerName = 'localhost', bool $encrypted = false, array $middlewares = [], array $responders = [], ReverseProxySettings $proxy = null)
    {
        $this->peer = $peer;
        $this->peerName = $peerName;
        $this->encrypted = $encrypted;
        $this->middlewares = $middlewares;
        $this->responders = $responders;
        $this->proxy = $proxy ?? new ReverseProxySettings();
    }

    /**
     * Get the local address that the server is bound to (including port).
     * 
     * @return string
     */
    public function getPeer(): string
    {
        return $this->peer;
    }
    
    /**
     * Get peer name of the server.
     * 
     * @return string
     */
    public function getPeerName(): string
    {
        return $this->peerName;
    }

    /**
     * Check if the server is using SSL / TLS encryption.
     * 
     * @return bool
     */
    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /**
     * Get all registered HTTP server middlewares.
     * 
     * @return array
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
    
    /**
     * Convert the given outcome of an action into an HTTP response.
     * 
     * @param HttpRequest $request
     * @param mixed $result
     * @return HttpResponse
     */
    public function respond(HttpRequest $request, $result): HttpResponse
    {
        if ($result instanceof HttpResponse) {
            return $result->withProtocolVersion($request->getProtocolVersion());
        }
        
        foreach ($this->responders as $responder) {
            $response = ($responder->callback)($request, $result);
            
            if ($response instanceof HttpResponse) {
                return $response->withProtocolVersion($request->getProtocolVersion());
            }
        }
        
        $reason = \sprintf('Expecting HttpResponse, given %s', \is_object($result) ? \get_class($result) : \gettype($result));
        
        throw new \RuntimeException($reason);
    }

    /**
     * Get HTTP reverse proxy settings.
     * 
     * @return ReverseProxySettings
     */
    public function getProxySettings(): ReverseProxySettings
    {
        return $this->proxy;
    }
}
