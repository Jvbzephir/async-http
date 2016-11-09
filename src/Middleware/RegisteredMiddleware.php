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

namespace KoolKode\Async\Http\Middleware;

/**
 * Holds a registered middleware.
 * 
 * @author Martin Schröder
 */
class RegisteredMiddleware
{
    /**
     * The callback provides by or as middleware.
     * 
     * @var callable
     */
    public $callback;

    /**
     * Registered priority of the middleware.
     * 
     * @var int
     */
    public $priority;

    /**
     * Create a new middleware registration.
     * 
     * @param callable $callback The callback provides by or as middleware.
     * @param int $priority Registered priority of the middleware.
     */
    public function __construct(callable $callback, int $priority)
    {
        $this->callback = $callback;
        $this->priority = $priority;
    }
}