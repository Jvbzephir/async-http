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
 * Optional contract for HTTP middleware implemented as class.
 * 
 * Any callable (including classes declaring __invoke) can be used as middleware.
 * 
 * @author Martin Schröder
 */
interface Middleware
{
    /**
     * Get the default priority of this middleware.
     * 
     * @return int
     */
    public function getDefaultPriority(): int;

    /**
     * Wrap middleware logic around dispatching of the given HTTP request.
     * 
     * Middleware may skip invoking next middleware if the request has been dispatched successfully.
     * 
     * @param HttpRequest $request The request to be dispatched.
     * @param NextMiddleware $next Provides delegation of the dispatch process.
     * @return HttpResponse
     */
    public function __invoke(HttpRequest $request, NextMiddleware $next): \Generator;
}
