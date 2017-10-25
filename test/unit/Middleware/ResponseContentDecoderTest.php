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
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Middleware\ResponseContentDecoder
 */
class ResponseContentDecoderTest extends AsyncTestCase
{
    protected function setUp()
    {
        return parent::setUp();
    
        if (!\KOOLKODE_ASYNC_ZLIB) {
            return $this->markTestSkipped('Test requires zlib support for incremental decompression');
        }
    }
    
    public function testDeclaresDefaultPriority()
    {
        $decoder = new ResponseContentDecoder();
        
        $this->assertEquals(-100000, $decoder->getDefaultPriority());
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
        
        $next = NextMiddleware::wrap(new ResponseContentDecoder(), function (Context $context, HttpRequest $request) use ($message, $name, $func) {
            $this->assertEquals([
                'gzip',
                'deflate'
            ], $request->getHeaderTokenValues('Accept-Encoding'));
            
            $response = new HttpResponse();
            
            if ($name !== '') {
                $response = $response->withHeader('Content-Encoding', $name);
            }
            
            return $response->withBody(new StringBody($func($message)));
        });
        
        $response = yield from $next($context, new HttpRequest('http://localhost/'));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertEquals($message, yield $response->getBody()->getContents($context));
    }
}
