<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

use KoolKode\Async\Context;
use KoolKode\Async\Success;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\HttpClient
 */
class HttpClientTest extends AsyncTestCase
{
    public function provideBaseUrls()
    {
        yield ['http://test.me', '', 'http://test.me/'];
        yield ['http://test.me', 'foo', 'http://test.me/foo'];
        yield ['http://test.me/foo', '/bar', 'http://test.me/bar'];
        yield ['http://test.me/foo', 'bar', 'http://test.me/bar'];
        yield ['http://test.me/foo/', 'bar', 'http://test.me/foo/bar'];
    }
    
    /**
     * @dataProvider provideBaseUrls
     */
    public function testBaseUri(Context $context, string $base, string $uri, string $result)
    {
        $connector = $this->createMock(HttpConnector::class);
        $connector->expects($this->once())->method('isRequestSupported')->will($this->returnValue(true));
        $connector->expects($this->once())->method('isConnected')->will($this->returnValue(new Success($context, true)));
        $connector->expects($this->once())->method('send')->will($this->returnCallback(function ($context, HttpRequest $request) use ($result) {
            $this->assertEquals($result, (string) $request->getUri());
            
            return new Success($context, new HttpResponse());
        }));
        
        $client = new HttpClient($connector);
        $client = $client->withBaseUri($base);
        
        yield $client->get($uri)->send($context);
    }
}
