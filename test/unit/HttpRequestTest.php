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

/**
 * @covers \KoolKode\Async\Http\HttpRequest
 */
class HttpRequestTest extends \PHPUnit_Framework_TestCase
{
    public function testDebugInfo()
    {
        $body = new StringBody('Test');
        
        $request = new HttpRequest('http://localhost/', Http::POST, [
            'foo' => 'bar'
        ], '1.0');
        $request = $request->withBody($body);
        $request = $request->withAttribute('foo', 'bar');
        
        $this->assertEquals([
            'protocol' => 'HTTP/1.0',
            'method' => Http::POST,
            'uri' => 'http://localhost/',
            'target' => '/',
            'headers' => [
                'Foo: bar',
                'Host: localhost'
            ],
            'body' => $body,
            'attributes' => [
                'foo'
            ]
        ], $request->__debugInfo());
    }
    
    public function testCanSetUriAndMethodInConstructor()
    {
        $request = new HttpRequest('http://localhost/', Http::POST);
        
        $this->assertEquals('http://localhost/', (string) $request->getUri());
        $this->assertEquals(Http::POST, $request->getMethod());
    }

    public function testCanChangeMethod()
    {
        $request = new HttpRequest('http://localhost/');
        $this->assertEquals(Http::GET, $request->getMethod());
        
        $this->assertNotSame($request, $request = $request->withMethod(Http::PUT));
        $this->assertEquals(Http::PUT, $request->getMethod());
    }
    
    public function provideInvalidMethods()
    {
        yield [''];
        yield ['?'];
    }
    
    /**
     * @dataProvider provideInvalidMethods
     */
    public function testDetectsInvalidMethodInConstructor(string $method)
    {
        $this->expectException(\InvalidArgumentException::class);
        
        new HttpRequest('http://localhost/', $method);
    }
    
    /**
     * @dataProvider provideInvalidMethods
     */
    public function testDetectsInvalidMethodInMutator(string $method)
    {
        $request = new HttpRequest('http://localhost/');
        
        $this->expectException(\InvalidArgumentException::class);
    
        $request->withMethod($method);
    }
    
    public function testCanSetHeadersUsingConstructor()
    {
        $request = new HttpRequest('http://localhost/', Http::GET, [
            'Foo' => 'bar'
        ]);
        
        $this->assertTrue($request->hasHeader('Foo'));
        $this->assertEquals('bar', $request->getHeaderLine('Foo'));
        $this->assertTrue($request->hasHeader('Host'));
        $this->assertEquals('localhost', $request->getHeaderLine('Host'));
        $this->assertFalse($request->hasHeader('Bar'));
        
        $this->assertEquals([
            'foo' => [
                'bar'
            ],
            'host' => [
                'localhost'
            ]
        ], $request->getHeaders());
    }
    
    public function testCanAccessQueryParams()
    {
        $request = new HttpRequest('http://localhost/test?foo=bar&test=yes');
        
        $this->assertTrue($request->hasQueryParam('foo'));
        $this->assertFalse($request->hasQueryParam('bar'));
        $this->assertTrue($request->hasQueryParam('test'));
        
        $this->assertEquals('bar', $request->getQueryParam('foo'));
        $this->assertEquals('#', $request->getQueryParam('bar', '#'));
        $this->assertEquals('yes', $request->getQueryParam('test'));
        
        $this->assertEquals([
            'foo' => 'bar',
            'test' => 'yes'
        ], $request->getQueryParams());
    }
    
    public function testCanAccessRequestTarget()
    {
        $request = new HttpRequest('http://localhost/test');
        $this->assertEquals('/test', $request->getRequestTarget());
        
        $this->assertNotSame($request, $request = $request->withRequestTarget('/foo'));
        $this->assertEquals('/foo', $request->getRequestTarget());
    }
    
    public function testRequestTargetMustNotContainWhitespace()
    {
        $request = new HttpRequest('http://localhost/');
        
        $this->expectException(\InvalidArgumentException::class);
        
        $request->withRequestTarget('foo bar');
    }

    public function testCanChangeUri()
    {
        $request = new HttpRequest('http://localhost/');
        $uri = $request->getUri();
        $this->assertEquals('http://localhost/', (string) $uri);
        
        $this->assertNotSame($request, $request = $request->withUri('http://test.me/'));
        $this->assertNotSame($uri, $request->getUri());
        $this->assertEquals('http://test.me/', (string) $request->getUri());
    }
    
    public function testDetectsExpectedContinue()
    {
        $request = new HttpRequest('http://localhost/');
        $this->assertFalse($request->isContinueExpected());
        
        $request = $request->withHeader('Expect', '100-continue', '199-crap');
        $this->assertTrue($request->isContinueExpected());
    }
}
