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
     */
    public function withMiddleware(callable $middleware, ?int $priority = null): self
    {
        if ($priority === null) {
            if ($middleware instanceof Middleware) {
                $priority = $middleware->getDefaultPriority();
            } else {
                $priority = 0;
            }
        }
        
        $object = clone $this;
        
        for ($inserted = null, $size = \count($object->middlewares), $i = 0; $i < $size; $i++) {
            if ($object->middlewares[$i]->priority < $priority) {
                $inserted = \array_splice($object->middlewares, $i, 0, [
                    new RegisteredMiddleware($middleware, $priority)
                ]);
                
                break;
            }
        }
        
        if ($inserted === null) {
            $object->middlewares[] = new RegisteredMiddleware($middleware, $priority);
        }
        
        return $object;
    }
}
