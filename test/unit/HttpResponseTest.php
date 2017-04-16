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

use KoolKode\Async\Http\Body\StringBody;
use PHPUnit\Framework\TestCase;

/**
 * @covers \KoolKode\Async\Http\HttpResponse
 */
class HttpResponseTest extends TestCase
{
    public function testDebugInfo()
    {
        $body = new StringBody('Test!');
        
        $response = new HttpResponse(Http::SEE_OTHER, [
            'test-foo' => 'bar'
        ], null, '1.0');
        $response = $response->withStatus(Http::NO_CONTENT, 'Test');
        $response = $response->withAddedHeader('Test-Foo', 'baz');
        $response = $response->withBody($body);
        $response = $response->withAttribute('foo', 'bar');
        
        $this->assertEquals([
            'protocol' => 'HTTP/1.0',
            'status' => Http::NO_CONTENT,
            'reason' => 'Test',
            'headers' => [
                'Test-Foo: bar',
                'Test-Foo: baz'
            ],
            'body' => $body,
            'attributes' => [
                'foo'
            ]
        ], $response->__debugInfo());
    }
    
    public function testCanSetStatusCodeViaConstructor()
    {
        $response = new HttpResponse(Http::NO_CONTENT);
        
        $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
        $this->assertEquals('', $response->getReasonPhrase());
    }
    
    public function provideInvalidStatusCodes()
    {
        yield [0];
        yield [99];
        yield [600];
        yield [9999];
    }
    
    /**
     * @dataProvider provideInvalidStatusCodes
     */
    public function testDetectsInvalidStatusCodeInConstructor(int $status)
    {
        $this->expectException(\InvalidArgumentException::class);
        
        new HttpResponse($status);
    }

    /**
     * @dataProvider provideInvalidStatusCodes
     */
    public function testDetectsInvalidStatusCodeInMutator(int $status)
    {
        $response = new HttpResponse();
        
        $this->expectException(\InvalidArgumentException::class);
        
        $response->withStatus($status);
    }
    
    public function testCanChangeStatusAndReason()
    {
        $response = new HttpResponse();
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('', $response->getReasonPhrase());
        
        $this->assertNotSame($response, $response = $response->withStatus(Http::BAD_REQUEST, 'Error'));
        $this->assertEquals(Http::BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals('Error', $response->getReasonPhrase());
        
        $this->assertNotSame($response, $response = $response->withReason('Foo'));
        $this->assertEquals(Http::BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals('Foo', $response->getReasonPhrase());
    }
}
