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

use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Middleware\FollowRedirects
 */
class FollowRedirectsTest extends AsyncTestCase
{
    public function testDeclaresDefaultPriority()
    {
        $redirects = new FollowRedirects();
        
        $this->assertEquals(-100001, $redirects->getDefaultPriority());
    }

    public function testFollowsRedirectWithIdenticalRequest()
    {
        $next = NextMiddleware::wrap(new FollowRedirects(), function (HttpRequest $request) {
            $this->assertEquals(Http::POST, $request->getMethod());
            
            if ($request->getRequestTarget() === '/test') {
                return new HttpResponse(Http::TEMPORARY_REDIRECT, [
                    'Location' => 'http://localhost/redirected'
                ]);
            }
            
            return new HttpResponse(Http::OK, [
                'Content-Type' => $request->getHeaderLine('Content-Type')
            ], new StringBody(yield $request->getBody()->getContents()));
        });
        
        $payload = 'Test Payload :)';
        
        $request = new HttpRequest('http://localhost/test', Http::POST, [
            'Content-Type' => 'text/plain'
        ], new StreamBody(new ReadableMemoryStream($payload)));
        
        $response = yield from $next($request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertEquals($payload, yield $response->getBody()->getContents());
    }

    public function testWillSwitchToGet()
    {
        $next = NextMiddleware::wrap(new FollowRedirects(), function (HttpRequest $request) {
            if ($request->getRequestTarget() === '/test') {
                $this->assertEquals(Http::POST, $request->getMethod());
                $this->assertEquals('text/plain', $request->getHeaderLine('Content-Type'));
                $this->assertEquals('Test Body', yield $request->getBody()->getContents());
                
                return new HttpResponse(Http::SEE_OTHER, [
                    'Location' => 'http://localhost/redirected?foo=bar'
                ]);
            }
            
            $this->assertEquals(Http::GET, $request->getMethod());
            $this->assertEquals('bar', $request->getQueryParam('foo'));
            $this->assertEquals('', yield $request->getBody()->getContents());
            
            return new HttpResponse(Http::OK, [
                'Content-Type' => 'text/plain'
            ], new StringBody('Echo Body'));
        });
        
        $request = new HttpRequest('http://localhost/test', Http::POST, [
            'Content-Type' => 'text/plain'
        ], new StringBody('Test Body'));
        
        $response = yield from $next($request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('Echo Body', yield $response->getBody()->getContents());
    }

    public function testEnforcesRedirectLimit()
    {
        $next = NextMiddleware::wrap(new FollowRedirects(), function (HttpRequest $request) {
            return new HttpResponse(Http::TEMPORARY_REDIRECT, [
                'Location' => 'http://localhost/redirected'
            ]);
        });
        
        $response = yield from $next(new HttpRequest('http://localhost/test'));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }
}
