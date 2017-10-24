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
 * @covers \KoolKode\Async\Http\Middleware\PathMatcher
 */
class PathMatcherTest extends AsyncTestCase
{
    public function testPriorityFromWrappedMiddleware()
    {
        $matcher = new PathMatcher('/foo', $middleware = new ResponseContentDecoder());
        
        $this->assertEquals($middleware->getDefaultPriority(), $matcher->getDefaultPriority());
    }
    
    public function testWillForwardIfPathDoesNotMatch(Context $context)
    {
        $matcher = new PathMatcher('/bar', function (Context $context, HttpRequest $request, NextMiddleware $next) {
            throw new \RuntimeException('Must not be called!');
        });
        
        $next = NextMiddleware::wrap($matcher, function (Context $context, HttpRequest $request) {
            return new HttpResponse();
        });
        
        $response = yield from $next($context, new HttpRequest('http://test.me/foo/bar'));
        
        $this->assertEquals(Http::OK, $response->getStatusCode());
    }

    public function testMatchWithPathParams(Context $context)
    {
        $matcher = new PathMatcher('/news/([0-9]+)-(.+)\\.(.+)', function (Context $context, HttpRequest $request, NextMiddleware $next, $id, $slug, $format) {
            $response = yield from $next($context, $request);
            $response = $response->withHeader('Path-Match', json_encode([
                $id,
                $slug,
                $format
            ]));
            
            return $response;
        });
        
        $next = NextMiddleware::wrap($matcher, function (Context $context, HttpRequest $request) {
            return new HttpResponse(Http::CREATED);
        });
        
        $response = yield from $next($context, new HttpRequest('http://test.me/news/123-my-news.html'));
        
        $this->assertEquals(Http::CREATED, $response->getStatusCode());
        $this->assertEquals([
            '123',
            'my-news',
            'html'
        ], json_decode($response->getHeaderLine('Path-Match'), true));
    }
}
