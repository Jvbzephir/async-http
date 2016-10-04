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
 * @covers \KoolKode\Async\Http\HttpMessage
 */
class HttpMessageTest extends \PHPUnit_Framework_TestCase
{
    public function testCanUseProtocolVersion()
    {
        $message = new HttpResponse(Http::OK, [], '1.0');
        $this->assertEquals('1.0', $message->getProtocolVersion());
        
        $message = $message->withProtocolVersion('1.1');
        $this->assertEquals('1.1', $message->getProtocolVersion());
    }

    public function testCanSetHeadersUsingConstructor()
    {
        $message = new HttpResponse(Http::OK, [
            'foo' => 'bar'
        ]);
        
        $this->assertTrue($message->hasHeader('Foo'));
        $this->assertEquals('bar', $message->getHeaderLine('Foo'));
        $this->assertEquals('', $message->getHeaderLine('Bar'));
        $this->assertEquals([
            'foo' => [
                'bar'
            ]
        ], $message->getHeaders());
    }

    public function testCanManipulateHeaders()
    {
        $message = new HttpResponse();
        $this->assertFalse($message->hasHeader('Foo'));
        
        $message = $message->withHeader('Foo', 'bar');
        $this->assertTrue($message->hasHeader('Foo'));
        $this->assertEquals('bar', $message->getHeaderLine('Foo'));
        
        $message = $message->withAddedHeader('Foo', 'baz');
        $this->assertTrue($message->hasHeader('Foo'));
        $this->assertEquals([
            'bar',
            'baz'
        ], $message->getHeader('Foo'));
        
        $message = $message->withoutHeader('Foo');
        $this->assertFalse($message->hasHeader('Foo'));
        $this->assertEquals('', $message->getHeaderLine('Foo'));
        $this->assertEquals([], $message->getHeader('Foo'));
    }
    
    public function provideHeaderInjectionVectors()
    {
        yield ["Foo\nbar", "..."];
        yield ["Foo\rbar", "..."];
        yield ["Test", "Foo\nbar"];
        yield ["Test", "Foo\rbar"];
    }
    
    /**
     * @dataProvider provideHeaderInjectionVectors
     */
    public function testDetetcsHeaderInjectionVectors(string $name, string $value)
    {
        $message = new HttpResponse();
       
        $this->expectException(\InvalidArgumentException::class);
        
        $message->withHeader($name, $value);
    }

    public function testCanUseBody()
    {
        $message = new HttpResponse();
        $body = new StringBody('Test');
        
        $this->assertInstanceOf(StringBody::class, $message->getBody());
        $this->assertNotSame($body, $message->getBody());
        
        $message = $message->withBody($body);
        $this->assertSame($body, $message->getBody());
    }

    public function testMessageAttributes()
    {
        $message = new HttpResponse();
        $this->assertNull($message->getAttribute('foo'));
        $this->assertEquals([], $message->getAttributes());
        
        $message = $message->withAttribute('foo', 'bar');
        $this->assertEquals('bar', $message->getAttribute('foo'));
        $this->assertEquals('#', $message->getAttribute('bar', '#'));
        
        $message = $message->withAttributes([
            'bar' => '#'
        ]);
        $this->assertEquals('#', $message->getAttribute('bar'));
        
        $message = $message->withoutAttribute('foo');
        $this->assertNull($message->getAttribute('foo'));
        $this->assertEquals('#', $message->getAttribute('bar'));
    }
}
