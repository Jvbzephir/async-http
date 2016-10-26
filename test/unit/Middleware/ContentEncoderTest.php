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
use KoolKode\Async\Http\NextMiddleware;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Http\StringBody;

/**
 * @covers \KoolKode\Async\Http\Middleware\ContentEncoder
 */
class ContentEncoderTest extends AsyncTestCase
{
    public function provideEncodingSettings()
    {
        yield ['', 'trim'];
        yield ['gzip', 'gzdecode'];
        yield ['deflate', 'gzuncompress'];
    }
    
    /**
     * @dataProvider provideEncodingSettings
     */
    public function testWillDecodeBody(string $name, string $func)
    {
        $message = 'Hello decoded world! :)';
        
        $middlewares = new \SplPriorityQueue();
        $middlewares->insert(new ContentEncoder(), 0);
        
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
}
