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
 * Adds HTTP middleware registration support.
 * 
 * @author Martin Schröder
 */
trait MiddlewareSupported
{
    /**
     * Registerd HTTP middlewares.
     * 
     * @var array
     */
    protected $middlewares = [];

    /**
     * Register a new HTTP middleware.
     * 
     * @param callable $middleware
     * @param int $priority
     */
    public function addMiddleware(callable $middleware, int $priority = null)
    {
        if ($priority === null) {
            if ($middleware instanceof HttpMiddleware) {
                $priority = $middleware->getDefaultPriority();
            } else {
                $priority = 0;
            }
        }
        
        for ($size = \count($this->middlewares), $i = 0; $i < $size; $i++) {
            if ($this->middlewares[$i]->priority < $priority) {
                \array_splice($this->middlewares, $i, 0, [
                    new RegisteredMiddleware($middleware, $priority)
                ]);
                
                return;
            }
        }
        
        $this->middlewares[] = new RegisteredMiddleware($middleware, $priority);
    }
}
