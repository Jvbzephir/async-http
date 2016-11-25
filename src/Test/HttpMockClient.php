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

/**
 * HTTP client mock that returns queued HTTP responses.
 * 
 * @author Martin Schröder
 */
class HttpMockClient extends HttpClient
{
    protected $responses;

    public function __construct(array $responses)
    {
        parent::__construct();
        
        $this->responses = $responses;
    }

    /**
     * {@inheritdoc}
     */
    public function shutdown(): Awaitable
    {
        return new Success(null);
    }

    /**
     * {@inheritdoc}
     */
    public function send(HttpRequest $request): Awaitable
    {
        if (!$request->hasHeader('User-Agent')) {
            $request = $request->withHeader('User-Agent', $this->userAgent);
        }
        
        $invoke = \Closure::bind(function (HttpRequest $request) {
            $next = new NextMiddleware($this->middlewares, function (HttpRequest $request) {
                return \array_shift($this->responses);
            });
            
            return new Coroutine($next($request));
        }, $this, HttpClient::class);
        
        return $invoke($request);
    }
}
