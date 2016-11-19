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
    
    public function testDeclaresDefaultPriority()
    {
        $encoder = new ContentEncoder();
    
        $this->assertEquals(100000, $encoder->getDefaultPriority());
    }
    
    public function provideEncodingSettings()
    {
        yield ['', 'trim'];
        yield ['foo', 'trim'];
        yield ['gzip', 'gzdecode'];
        yield ['deflate', 'gzuncompress'];
    }
    
    /**
     * @dataProvider provideEncodingSettings
     */
    public function testWillEncodeResponseBodies(string $name, string $func)
    {
        $message = 'Hello decoded world! :)';
        
        $next = NextMiddleware::wrap(new ContentEncoder([
            'text/plain'
        ]), function (HttpRequest $request) use ($message, $name, $func) {
            $response = new HttpResponse();
            $response = $response->withHeader('Content-Type', 'text/plain');
            
            return $response->withBody(new StringBody($message));
        });
        
        $request = new HttpRequest('http://localhost/');
        $request = $request->withHeader('Accept-Encoding', $name);
        
        $response = yield from $next($request);
        
        $this->assertTrue($response instanceof HttpResponse);
        
        if ($name !== '' && $name !== 'foo') {
            $this->assertTrue($response->hasHeader('Content-Encoding'));
        }
        
        $this->assertEquals('Accept-Encoding', $response->getHeaderLine('Vary'));
        $this->assertEquals($message, $func(yield $response->getBody()->getContents()));
    }
    
    public function testWillNotEncodeResponseToHeadRequest()
    {
        $next = NextMiddleware::wrap(new ContentEncoder(), function (HttpRequest $request) {
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
        $next = NextMiddleware::wrap(new ContentEncoder(), function (HttpRequest $request) use ($response) {
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
        $next = NextMiddleware::wrap(new ContentEncoder(), function (HttpRequest $request) {
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
        
        $next = NextMiddleware::wrap($encoder, function (HttpRequest $request) {
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
        
        $next = NextMiddleware::wrap($encoder, function (HttpRequest $request) {
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
