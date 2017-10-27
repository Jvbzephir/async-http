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
 * @covers \KoolKode\Async\Http\Middleware\PublishFiles
 */
class PublishFilesTest extends AsyncTestCase
{
    public function testDefaults()
    {
        $middleware = new PublishFiles(__DIR__);
        
        $this->assertEquals(0, $middleware->getDefaultPriority());
    }
    
    public function provideUnmatchedRequests()
    {
        yield [
            new HttpRequest('http://test.me/', Http::POST)
        ];
        
        yield [
            new HttpRequest('http://test.me/../foo')
        ];
        
        yield [
            new HttpRequest('http://test.me/foo')
        ];
    }

    /**
     * @dataProvider provideUnmatchedRequests
     */
    public function test(Context $context, HttpRequest $request)
    {
        $next = NextMiddleware::wrap(new PublishFiles(__DIR__), function () {
            return new HttpResponse(Http::NOT_FOUND);
        });
        
        $response = yield from $next($context, $request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NOT_FOUND, $response->getStatusCode());
    }
    
    public function testHttp10(Context $context)
    {
        $next = NextMiddleware::wrap(new PublishFiles(__DIR__), function () {
            return new HttpResponse(Http::NOT_FOUND);
        });
        
        $response = yield from $next($context, new HttpRequest('http://test.me/PublishFilesTest.php', Http::GET, [], null, '1.0'));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Cache-Control'));
        $this->assertTrue($response->hasHeader('Expires'));
        $this->assertEquals(file_get_contents(__FILE__), yield $response->getBody()->getContents($context));
    }

    public function testHttp11(Context $context)
    {
        $next = NextMiddleware::wrap(new PublishFiles(__DIR__, 3600), function () {
            return new HttpResponse(Http::NOT_FOUND);
        });
        
        $response = yield from $next($context, new HttpRequest('http://test.me/PublishFilesTest.php'));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
        $this->assertFalse($response->hasHeader('Expires'));
        $this->assertEquals(file_get_contents(__FILE__), yield $response->getBody()->getContents($context));
    }
}
