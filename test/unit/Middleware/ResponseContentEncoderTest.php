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
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Middleware\ResponseContentEncoder
 */
class ResponseContentEncoderTest extends AsyncTestCase
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
        $encoder = new ResponseContentEncoder();
    
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
    public function testWillEncodeResponseBodies(Context $context, string $name, string $func)
    {
        $message = 'Hello decoded world! :)';
        
        $next = NextMiddleware::wrap(new ResponseContentEncoder([
            'text/plain'
        ]), function (Context $context, HttpRequest $request) use ($message, $name, $func) {
            return new HttpResponse(Http::OK, [
                'Content-Type' => 'text/plain'
            ], new StringBody($message));
        });
        
        $request = new HttpRequest('http://localhost/', Http::GET, [
            'Accept-Encoding' => $name
        ]);
        
        $response = yield from $next($context, $request);
        
        $this->assertTrue($response instanceof HttpResponse);
        
        if ($name !== '' && $name !== 'foo') {
            $this->assertTrue($response->hasHeader('Content-Encoding'));
        }
        
        $this->assertEquals('Accept-Encoding', $response->getHeaderLine('Vary'));
        $this->assertEquals($message, $func(yield $response->getBody()->getContents($context)));
    }
    
    public function testWillNotEncodeResponseToHeadRequest(Context $context)
    {
        $next = NextMiddleware::wrap(new ResponseContentEncoder(), function (Context $context, HttpRequest $request) {
            return new HttpResponse(Http::OK, [], new StringBody('Foo'));
        });
        
        $request = new HttpRequest('http://localhost/', Http::HEAD, [
            'Accept-Encoding' => 'gzip, deflate'
        ]);
        
        $response = yield from $next($context, $request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }
    
    public function provideUncompressableResponses()
    {
        yield [new HttpResponse(Http::NO_CONTENT)];
        yield [new HttpResponse()];
        yield [new HttpResponse(Http::OK, ['Content-Type' => 'text/x-foo'])];
        yield [new HttpResponse(Http::OK, ['Content-Type' => 'foo'])];
    }

    /**
     * @dataProvider provideUncompressableResponses
     */
    public function testWillNotEncodeUmcompressableResponse(Context $context, HttpResponse $response)
    {
        $next = NextMiddleware::wrap(new ResponseContentEncoder(), function (Context $context, HttpRequest $request) use ($response) {
            return $response;
        });
        
        $request = new HttpRequest('http://localhost/', Http::GET, [
            'Accept-Encoding' => 'gzip, deflate'
        ]);
        
        $response = yield from $next($context, $request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }
    
    public function testWillNotDoubleEncodeResponse(Context $context)
    {
        $next = NextMiddleware::wrap(new ResponseContentEncoder(), function (Context $context, HttpRequest $request) {
            return new HttpResponse(Http::OK, [
                'Content-Encoding' => 'foo'
            ]);
        });
        
        $request = new HttpRequest('http://localhost/', Http::GET, [
            'Accept-Encoding' => 'gzip, deflate'
        ]);
        
        $response = yield from $next($context, $request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals('foo', $response->getHeaderLine('Content-Encoding'));
    }
    
    public function testCanAddCompressableType(Context $context)
    {
        $encoder = new ResponseContentEncoder();
        $encoder->addType('foo/bar');
        
        $next = NextMiddleware::wrap($encoder, function (Context $context, HttpRequest $request) {
            return new HttpResponse(Http::OK, [
                'Content-Type' => 'foo/bar'
            ]);
        });
        
        $request = new HttpRequest('http://localhost/', Http::GET, [
            'Accept-Encoding' => 'gzip, deflate'
        ]);
        
        $response = yield from $next($context, $request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertTrue($response->hasHeader('Content-Encoding'));
    }

    public function testCanAddCompressableSubType(Context $context)
    {
        $encoder = new ResponseContentEncoder(null, [
            'foo'
        ]);
        $encoder->addSubType('bar');
        
        $next = NextMiddleware::wrap($encoder, function (Context $context, HttpRequest $request) {
            return new HttpResponse(Http::OK, [
                'Content-Type' => 'foo/bar'
            ]);
        });
        
        $request = new HttpRequest('http://localhost/', Http::GET, [
            'Accept-Encoding' => 'gzip, deflate'
        ]);
        
        $response = yield from $next($context, $request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertTrue($response->hasHeader('Content-Encoding'));
    }
}
