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
     * @var \SplPriorityQueue
     */
    public $middleware;
    
    /**
     * Create a new HTTP server / driver context.
     * 
     * @param string $peerName
     * @param bool $encrypted
     * @param \SplPriorityQueue $middleware
     */
    public function __construct(string $peerName, bool $encrypted = false, \SplPriorityQueue $middleware = null)
    {
        $this->peerName = $peerName;
        $this->encrypted = $encrypted;
        $this->middleware = $middleware ?? new \SplPriorityQueue();
    }
}
