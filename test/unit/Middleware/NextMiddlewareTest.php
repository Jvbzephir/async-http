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

use KoolKode\Async\Context;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Middleware\NextMiddleware
 */
class NextMiddlewareTest extends AsyncTestCase
{
    public function testCanInvokeSyncAction(Context $context)
    {
        $next = new NextMiddleware([], function (Context $context, HttpRequest $request) {
            $this->assertEquals(Http::POST, $request->getMethod());
            
            return new HttpResponse(Http::NO_CONTENT);
        });
        
        $response = yield from $next($context, new HttpRequest('http://localhost/', Http::POST));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
    }

    public function testCanInvokeAsyncAction(Context $context)
    {
        $next = new NextMiddleware([], function (Context $context, HttpRequest $request) {
            $this->assertEquals(Http::POST, $request->getMethod());
            
            yield null;
            
            return new HttpResponse(Http::NO_CONTENT);
        });
        
        $response = yield from $next($context, new HttpRequest('http://localhost/', Http::POST));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
    }

    public function testCanInvokeSyncMiddleware(Context $context)
    {
        $next = NextMiddleware::wrap(function (Context $context, HttpRequest $request) {
            $this->assertEquals(Http::POST, $request->getMethod());
            
            return new HttpResponse(Http::NO_CONTENT);
        }, function () {});
        
        $response = yield from $next($context, new HttpRequest('http://localhost/', Http::POST));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
    }

    public function testCanInvokeAsyncMiddleware(Context $context)
    {
        $next = NextMiddleware::wrap(function (Context $context, HttpRequest $request) {
            $this->assertEquals(Http::POST, $request->getMethod());
            
            yield null;
            
            return new HttpResponse(Http::NO_CONTENT);
        }, function () {});
        
        $response = yield from $next($context, new HttpRequest('http://localhost/', Http::POST));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
    }

    public function testDetectsMissingHttpResponse(Context $context)
    {
        $next = new NextMiddleware([], function () {});
        
        $this->expectException(\UnexpectedValueException::class);
        
        yield from $next($context, new HttpRequest('http://localhost/'));
    }
}
