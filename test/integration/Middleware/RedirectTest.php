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

use KoolKode\Async\Http\Test\EndToEndTest;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\StreamBody;
use KoolKode\Async\Http\StringBody;
use KoolKode\Async\Stream\ReadableMemoryStream;

/**
 * @covers \KoolKode\Async\Http\Middleware\FollowRedirects
 */
class RedirectTest extends EndToEndTest
{
    public function testFollowsRedirectWithIdenticalRequest()
    {
        $this->clientMiddleware->insert(new FollowRedirects(), 0);
        
        $payload = 'Test Payload :)';
        
        $request = new HttpRequest('http://localhost/test', Http::POST);
        $request = $request->withHeader('Content-Type', 'text/plain');
        $request = $request->withBody(new StreamBody(new ReadableMemoryStream($payload)));
        
        $response = yield from $this->send($request, function (HttpRequest $request) {
            $this->assertEquals(Http::POST, $request->getMethod());
            
            if ($request->getRequestTarget() === '/test') {
                $response = new HttpResponse(Http::REDIRECT_IDENTICAL);
                $response = $response->withHeader('Location', 'http://localhost/redirected');
                
                return $response;
            }
            
            $response = new HttpResponse();
            $response = $response->withHeader('Content-Type', $request->getHeaderLine('Content-Type'));
            $response = $response->withBody(new StringBody(yield $request->getBody()->getContents()));
            
            return $response;
        });
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertEquals($payload, yield $response->getBody()->getContents());
    }

    public function testWillSwitchToGet()
    {
        $this->clientMiddleware->insert(new FollowRedirects(), 0);
        
        $request = new HttpRequest('http://localhost/test', Http::POST);
        $request = $request->withHeader('Content-Type', 'text/plain');
        $request = $request->withBody(new StringBody('Test Body'));
        
        $response = yield from $this->send($request, function (HttpRequest $request) {
            if ($request->getRequestTarget() === '/test') {
                $this->assertEquals(Http::POST, $request->getMethod());
                $this->assertEquals('text/plain', $request->getHeaderLine('Content-Type'));
                $this->assertEquals('Test Body', yield $request->getBody()->getContents());
                
                $response = new HttpResponse(Http::REDIRECT_TEMPORARY);
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
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('Echo Body', yield $response->getBody()->getContents());
    }

    public function testEnforcesRedirectLimit()
    {
        $this->clientMiddleware->insert(new FollowRedirects(3), 0);
        
        $request = new HttpRequest('http://localhost/test');
        
        $this->expectException(TooManyRedirectsException::class);
        
        yield from $this->send($request, function (HttpRequest $request) {
            $response = new HttpResponse(Http::REDIRECT_IDENTICAL);
            $response = $response->withHeader('Location', 'http://localhost/redirected');
            
            return $response;
        });
    }
}
