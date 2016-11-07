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

namespace KoolKode\Async\Http\Test;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\HttpClient;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Success;

class HttpMockClient extends HttpClient
{
    protected $responses;

    public function __construct(array $responses)
    {
        parent::__construct();
        
        $this->responses = $responses;
    }

    public function shutdown(): Awaitable
    {
        return new Success(null);
    }

    public function send(HttpRequest $request): Awaitable
    {
        if (!$request->hasHeader('User-Agent')) {
            $request = $request->withHeader('User-Agent', $this->userAgent);
        }
        
        $next = new NextMiddleware($this->middleware, function (HttpRequest $request) {
            return \array_shift($this->responses);
        });
        
        return new Coroutine($next($request));
    }
}
