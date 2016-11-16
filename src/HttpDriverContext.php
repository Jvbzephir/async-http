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

    public function getPeer(): string
    {
        return $this->peer;
    }

    public function getPeerName(): string
    {
        return $this->peerName;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
    
    public function getResponders(): array
    {
        return $this->responders;
    }

    public function getProxySettings(): ReverseProxySettings
    {
        return $this->proxy;
    }
}
