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
 * Extendable context object that is used to allow for persistent connections etc.
 * 
 * @author Martin Schröder
 */
interface HttpMiddleware
{
    /**
     * Invoke middleware and resolve into an HTTP response.
     * 
     * Middleware should be implemented as a PHP generator used as coroutine.
     * 
     * @param HttpRequest $request
     * @param NextMiddleware $next
     * @return HttpResponse
     */
    public function __invoke(HttpRequest $request, NextMiddleware $next);
}
