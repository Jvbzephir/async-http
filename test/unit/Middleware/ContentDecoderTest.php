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

use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Http\StringBody;

/**
 * @covers \KoolKode\Async\Http\Middleware\ContentDecoder
 */
class ContentDecoderTest extends AsyncTestCase
{
    public function provideEncodingSettings()
    {
        yield ['', 'trim'];
        yield ['gzip', 'gzencode'];
        yield ['deflate', 'gzcompress'];
    }
    
    /**
     * @dataProvider provideEncodingSettings
     */
    public function testWillDecodeBody(string $name, string $func)
    {
        $message = 'Hello decoded world! :)';
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert(new ContentDecoder(), 0);
        
        $next = new NextMiddleware($middlewares, function (HttpRequest $request) use ($message, $name, $func) {
            $this->assertEquals([
                'gzip',
                'deflate'
            ], $request->getHeaderTokens('Accept-Encoding'));
            
            $response = new HttpResponse();
            
            if ($name !== '') {
                $response = $response->withHeader('Content-Encoding', $name);
            }
            
            return $response->withBody(new StringBody($func($message)));
        });
        
        $response = yield from $next(new HttpRequest('http://localhost/'));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertEquals($message, yield $response->getBody()->getContents());
    }
}
