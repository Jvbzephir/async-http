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
                $response = new HttpResponse(Http::TEMPORARY_REDIRECT);
                $response = $response->withHeader('Location', 'http://localhost/redirected');
                
                return $response;
            }
            
            $response = new HttpResponse();
            $response = $response->withHeader('Content-Type', $request->getHeaderLine('Content-Type'));
            $response = $response->withBody(new StringBody(yield $request->getBody()->getContents()));
            
            return $response;
        });
        
        $payload = 'Test Payload :)';
        
        $request = new HttpRequest('http://localhost/test', Http::POST);
        $request = $request->withHeader('Content-Type', 'text/plain');
        $request = $request->withBody(new StreamBody(new ReadableMemoryStream($payload)));
        
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
                
                $response = new HttpResponse(Http::SEE_OTHER);
                $response = $response->withHeader('Location', 'http://localhost/redirected?foo=bar');
                
                return $response;
            }
            
            $this->assertEquals(Http::GET, $request->getMethod());
            $this->assertEquals('bar', $request->getQueryParam('foo'));
            $this->assertEquals('', yield $request->getBody()->getContents());
            
            $response = new HttpResponse();
            $response = $response->withHeader('Content-Type', 'text/plain');
            $response = $response->withBody(new StringBody('Echo Body'));
            
            return $response;
        });
        
        $request = new HttpRequest('http://localhost/test', Http::POST);
        $request = $request->withHeader('Content-Type', 'text/plain');
        $request = $request->withBody(new StringBody('Test Body'));
        
        $response = yield from $next($request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('Echo Body', yield $response->getBody()->getContents());
    }

    public function testEnforcesRedirectLimit()
    {
        $next = NextMiddleware::wrap(new FollowRedirects(), function (HttpRequest $request) {
            $response = new HttpResponse(Http::TEMPORARY_REDIRECT);
            $response = $response->withHeader('Location', 'http://localhost/redirected');
            
            return $response;
        });
        
        $this->expectException(TooManyRedirectsException::class);
        
        yield from $next(new HttpRequest('http://localhost/test'));
    }
}
