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

use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;

/**
 * Extendable context object that is used to allow for persistent connections etc.
 * 
 * @author Martin Schröder
 */
class NextMiddleware
{
    protected $middlewares;

    protected $target;

    public function __construct(\SplPriorityQueue $middlewares, callable $target)
    {
        $this->middlewares = clone $middlewares;
        $this->target = $target;
    }

    /**
     * Invoke next HTTP middleware or the decorated action.
     * 
     * @param HttpRequest $request
     * @return HttpResponse
     * 
     * @throws \RuntimeException When the middleware / action does not return / resolve into an HTTP response.
     */
    public function __invoke(HttpRequest $request): \Generator
    {
        if ($this->middlewares->isEmpty()) {
            $response = ($this->target)($request);
        } else {
            $response = $this->middlewares->extract()($request, $this);
        }
        
        if ($response instanceof \Generator) {
            $response = yield from $response;
        }
        
        if (!$response instanceof HttpResponse) {
            throw new \RuntimeException(\sprintf('Middleware must return an HTTP response, given %s', \is_object($response) ? \get_class($response) : \gettype($response)));
        }
        
        return $response;
    }
}
