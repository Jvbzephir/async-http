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
 * @covers \KoolKode\Async\Http\Middleware\RequestContentDecoder
 */
class RequestContentDecoderTest extends AsyncTestCase
{
    protected function setUp()
    {
        return parent::setUp();
    
        if (!function_exists('inflate_init')) {
            return $this->markTestSkipped('Test requires zlib support for incremental decompression');
        }
    }
    
    public function testDeclaresDefaultPriority()
    {
        $decoder = new RequestContentDecoder();
        
        $this->assertEquals(100000, $decoder->getDefaultPriority());
    }
    
    public function provideEncodingSettings()
    {
        yield ['', 'trim'];
        yield ['gzip', 'gzencode'];
        yield ['deflate', 'gzcompress'];
    }

    /**
     * @dataProvider provideEncodingSettings
     */
    public function testWillDecodeBody(Context $context, string $name, string $func)
    {
        $message = 'Hello decoded world! :)';
        
        $next = NextMiddleware::wrap(new RequestContentDecoder(), function (Context $context, HttpRequest $request) use ($message, $name) {
            $this->assertFalse($request->hasHeader('Content-Encoding'));
            $this->assertEquals('text/plain', $request->getHeaderLine('Content-Type'));
            
            return new HttpResponse(Http::OK, [
                'Content-Type' => 'text/plain'
            ], new StringBody(yield $request->getBody()->getContents($context)));
        });
        
        $request = new HttpRequest('http://localhost/', Http::POST, [
            'Content-Type' => 'text/plain'
        ], new StringBody($func($message)));
        
        if ($name !== '') {
            $request = $request->withHeader('Content-Encoding', $name);
        }
        
        $response = yield from $next($context, $request);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals($message, yield $response->getBody()->getContents($context));
    }
}
