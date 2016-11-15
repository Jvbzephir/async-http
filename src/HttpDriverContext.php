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
     * Server peer name.
     * 
     * @var string
     */
    public $peerName;
    
    /**
     * Is the HTTP endpoint encrypted using SSL / TSL.
     * 
     * @var bool
     */
    public $encrypted;
    
    /**
     * Registered prioritized HTTP middleware.
     * 
     * @var array
     */
    public $middlewares;
    
    /**
     * HTTP proxy mode settings.
     * 
     * @var ReverseProxySettings
     */
    public $proxy;
    
    /**
     * Create a new HTTP server / driver context.
     * 
     * @param string $peerName
     * @param bool $encrypted
     * @param array $middlewares
     */
    public function __construct(string $peerName, bool $encrypted = false, array $middlewares = [], ReverseProxySettings $proxy = null)
    {
        $this->peerName = $peerName;
        $this->encrypted = $encrypted;
        $this->middlewares = $middlewares;
        $this->proxy = $proxy ?? new ReverseProxySettings();
    }
}
