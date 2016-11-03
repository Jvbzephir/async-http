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

use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Middleware\ContentEncoder
 */
class ContentEncoderTest extends AsyncTestCase
{
    protected function setUp()
    {
        return parent::setUp();
        
        if (!function_exists('deflate_init')) {
            return $this->markTestSkipped('Test requires zlib support for incremental compression');
        }
    }
    
    public function provideEncodingSettings()
    {
        yield ['', 'trim'];
        yield ['gzip', 'gzdecode'];
        yield ['deflate', 'gzuncompress'];
    }
    
    /**
     * @dataProvider provideEncodingSettings
     */
    public function testWillEncodeResponseBodies(string $name, string $func)
    {
        $message = 'Hello decoded world! :)';
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert(new ContentEncoder([
            'text/plain'
        ]), 0);
        
        $next = new NextMiddleware($middlewares, function (HttpRequest $request) use ($message, $name, $func) {
            $response = new HttpResponse();
            $response = $response->withHeader('Content-Type', 'text/plain');
            
            return $response->withBody(new StringBody($message));
        });
        
        $request = new HttpRequest('http://localhost/');
        $request = $request->withHeader('Accept-Encoding', $name);
        
        $response = yield from $next($request);
        
        $this->assertTrue($response instanceof HttpResponse);
        
        if ($name !== '') {
            $this->assertTrue($response->hasHeader('Content-Encoding'));
        }
        
        $this->assertEquals($message, $func(yield $response->getBody()->getContents()));
    }
    
    public function testWillNotEncodeResponseToHeadRequest()
    {
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert(new ContentEncoder(), 0);
        
        $next = new NextMiddleware($middlewares, function (HttpRequest $request) {
            $response = new HttpResponse();
            $response = $response->withBody(new StringBody('Foo'));
            
            return $response;
        });
        
        $request = new HttpRequest('http://localhost/', Http::HEAD);
        $request = $request->withHeader('Accept-Encoding', 'gzip, deflate');
        
        $response = yield from $next($request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }
    
    public function provideUncompressableResponses()
    {
        yield [new HttpResponse(Http::NO_CONTENT)];
        yield [new HttpResponse()];
        
        $response = new HttpResponse();
        $response = $response->withHeader('Content-Type', 'text/x-foo');
        
        yield [$response];
        
        $response = new HttpResponse();
        $response = $response->withHeader('Content-Type', 'foo');
        
        yield [$response];
    }

    /**
     * @dataProvider provideUncompressableResponses
     */
    public function testWillNotEncodeUmcompressableResponse(HttpResponse $response)
    {
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert(new ContentEncoder(), 0);
        
        $next = new NextMiddleware($middlewares, function (HttpRequest $request) use ($response) {
            return $response;
        });
        
        $request = new HttpRequest('http://localhost/');
        $request = $request->withHeader('Accept-Encoding', 'gzip, deflate');
        
        $response = yield from $next($request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }
    
    public function testWillNotDoubleEncodeResponse()
    {
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert(new ContentEncoder(), 0);
        
        $next = new NextMiddleware($middlewares, function (HttpRequest $request) {
            $response = new HttpResponse();
            $response = $response->withHeader('Content-Encoding', 'foo');
            
            return $response;
        });
        
        $request = new HttpRequest('http://localhost/');
        $request = $request->withHeader('Accept-Encoding', 'gzip, deflate');
        
        $response = yield from $next($request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals('foo', $response->getHeaderLine('Content-Encoding'));
    }
    
    public function testCanAddCompressableType()
    {
        $encoder = new ContentEncoder();
        $encoder->addType('foo/bar');
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert($encoder, 0);
        
        $next = new NextMiddleware($middlewares, function (HttpRequest $request) {
            $response = new HttpResponse();
            $response = $response->withHeader('Content-Type', 'foo/bar');
            
            return $response;
        });
        
        $request = new HttpRequest('http://localhost/');
        $request = $request->withHeader('Accept-Encoding', 'gzip, deflate');
        
        $response = yield from $next($request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertTrue($response->hasHeader('Content-Encoding'));
    }

    public function testCanAddCompressableSubType()
    {
        $encoder = new ContentEncoder(null, [
            'foo'
        ]);
        $encoder->addSubType('bar');
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert($encoder, 0);
        
        $next = new NextMiddleware($middlewares, function (HttpRequest $request) {
            $response = new HttpResponse();
            $response = $response->withHeader('Content-Type', 'foo/bar');
            
            return $response;
        });
        
        $request = new HttpRequest('http://localhost/');
        $request = $request->withHeader('Accept-Encoding', 'gzip, deflate');
        
        $response = yield from $next($request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertTrue($response->hasHeader('Content-Encoding'));
    }
}
