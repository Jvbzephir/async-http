<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Middleware;

use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http;

/**
 * @covers \KoolKode\Async\Http\Middleware\NextMiddleware
 */
class NextMiddlewareTest extends AsyncTestCase
{
    public function testCanInvokeSyncAction()
    {
        $next = new NextMiddleware(new \SplPriorityQueue(), function (HttpRequest $request) {
            $this->assertEquals(Http::POST, $request->getMethod());
            
            return new HttpResponse(Http::NO_CONTENT);
        });
        
        $response = yield from $next(new HttpRequest('http://localhost/', Http::POST));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
    }

    public function testCanInvokeAsyncAction()
    {
        $next = new NextMiddleware(new \SplPriorityQueue(), function (HttpRequest $request) {
            $this->assertEquals(Http::POST, $request->getMethod());
            
            yield null;
            
            return new HttpResponse(Http::NO_CONTENT);
        });
        
        $response = yield from $next(new HttpRequest('http://localhost/', Http::POST));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
    }

    public function testCanInvokeSyncMiddleware()
    {
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert(function (HttpRequest $request) {
            $this->assertEquals(Http::POST, $request->getMethod());
            
            return new HttpResponse(Http::NO_CONTENT);
        }, 0);
        
        $next = new NextMiddleware($middlewares, function () {});
        
        $response = yield from $next(new HttpRequest('http://localhost/', Http::POST));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
    }

    public function testCanInvokeAsyncMiddleware()
    {
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert(function (HttpRequest $request) {
            $this->assertEquals(Http::POST, $request->getMethod());
            
            yield null;
            
            return new HttpResponse(Http::NO_CONTENT);
        }, 0);
        
        $next = new NextMiddleware($middlewares, function () {});
        
        $response = yield from $next(new HttpRequest('http://localhost/', Http::POST));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
    }

    public function testDetectsMissingHttpResponse()
    {
        $next = new NextMiddleware(new \SplPriorityQueue(), function () {});
        
        $this->expectException(\RuntimeException::class);
        
        yield from $next(new HttpRequest('http://localhost/'));
    }
}
