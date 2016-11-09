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

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Middleware\PublishFiles
 */
class PublishFilesTest extends AsyncTestCase
{
    public function testWillServerFileWithCacheHeader()
    {
        $publish = new PublishFiles(__DIR__, '/', 3600);
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert($publish, 0);
        
        $next = new NextMiddleware($middlewares, function () {
            return new HttpResponse(Http::NOT_FOUND);
        });
        
        $response = yield from $next(new HttpRequest('http://localhost/PublishFilesTest.php'));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
        $this->assertEquals(file_get_contents(__FILE__), yield $response->getBody()->getContents());
    }

    public function testWillAddExpiresHeaderToHttp10()
    {
        $publish = new PublishFiles(__DIR__, '/', 3600);
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert($publish, 0);
        
        $next = new NextMiddleware($middlewares, function () {
            return new HttpResponse(Http::NOT_FOUND);
        });
        
        $response = yield from $next(new HttpRequest('http://localhost/PublishFilesTest.php', Http::GET, [], '1.0'));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Expires'));
        $this->assertEquals(file_get_contents(__FILE__), yield $response->getBody()->getContents());
    }
    
    public function testWillUseBasePath()
    {
        $publish = new PublishFiles(__DIR__, '/asset');
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert($publish, 0);
        
        $next = new NextMiddleware($middlewares, function () {
            return new HttpResponse(Http::NOT_FOUND);
        });
        
        $response = yield from $next(new HttpRequest('http://localhost/asset/PublishFilesTest.php'));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals(file_get_contents(__FILE__), yield $response->getBody()->getContents());
    }
    
    public function testExcludeHttpMethodExceptForGetAndHead()
    {
        $publish = new PublishFiles(__DIR__);
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert($publish, 0);
        
        $next = new NextMiddleware($middlewares, function () {
            return new HttpResponse(Http::NOT_FOUND);
        });
        
        $response = yield from $next(new HttpRequest('http://localhost/PublishFilesTest.php', Http::POST));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NOT_FOUND, $response->getStatusCode());
    }

    public function testWillHonorBasePath()
    {
        $publish = new PublishFiles(__DIR__, '/app');
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert($publish, 0);
        
        $next = new NextMiddleware($middlewares, function () {
            return new HttpResponse(Http::NOT_FOUND);
        });
        
        $response = yield from $next(new HttpRequest('http://localhost/PublishFilesTest.php'));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NOT_FOUND, $response->getStatusCode());
    }
    
    public function testWillEnforceBaseDirectory()
    {
        $publish = new PublishFiles(__DIR__);
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert($publish, 0);
        
        $next = new NextMiddleware($middlewares, function () {
            return new HttpResponse(Http::NOT_FOUND);
        });
        
        $response = yield from $next(new HttpRequest('http://localhost/../HttpTest.php'));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NOT_FOUND, $response->getStatusCode());
    }
    
    public function testWillNotServeDirectory()
    {
        $publish = new PublishFiles(__DIR__);
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert($publish, 0);
        
        $next = new NextMiddleware($middlewares, function () {
            return new HttpResponse(Http::NOT_FOUND);
        });
        
        $response = yield from $next(new HttpRequest('http://localhost/'));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::NOT_FOUND, $response->getStatusCode());
    }
}
