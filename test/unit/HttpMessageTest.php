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

/**
 * @covers \KoolKode\Async\Http\HttpMessage
 */
class HttpMessageTest extends \PHPUnit_Framework_TestCase
{
    public function testCanUseProtocolVersion()
    {
        $message = new HttpResponse(Http::OK, [], '1.0');
        $this->assertEquals('1.0', $message->getProtocolVersion());
        
        $this->assertNotSame($message, $message = $message->withProtocolVersion('1.1'));
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
        
        $this->assertNotSame($message, $message = $message->withHeader('Foo', 'bar'));
        $this->assertTrue($message->hasHeader('Foo'));
        $this->assertEquals('bar', $message->getHeaderLine('Foo'));
        
        $this->assertNotSame($message, $message = $message->withAddedHeader('Foo', 'baz'));
        $this->assertTrue($message->hasHeader('Foo'));
        $this->assertEquals([
            'bar',
            'baz'
        ], $message->getHeader('Foo'));
        
        $this->assertNotSame($message, $message = $message->withoutHeader('Foo'));
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
    public function testDetetcsHeaderInjectionVectorsInConstructor(string $name, string $value)
    {
        $this->expectException(\InvalidArgumentException::class);
        
        new HttpResponse(Http::OK, [
            $name => $value
        ]);
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
    
    public function testCanTokenizeHeader()
    {
        $message = new HttpResponse();
        $message = $message->withHeader('Tokens', 'fOo, Bar', 'baZ');
        
        $this->assertEquals([
            'foo',
            'bar',
            'baz'
        ], $message->getHeaderTokenValues('Tokens'));
    }
    
    public function testCanTokenizeHeaderUsingSeparator()
    {
        $message = new HttpResponse();
        $message = $message->withHeader('Tokens', 'foo; bar', 'baz');
        
        $this->assertEquals([
            'foo',
            'bar',
            'baz'
        ], $message->getHeaderTokenValues('Tokens', true, ';'));
    }

    public function testCanUseBody()
    {
        $message = new HttpResponse();
        $body = new StringBody('Test');
        
        $this->assertInstanceOf(StringBody::class, $message->getBody());
        $this->assertNotSame($body, $message->getBody());
        
        $this->assertNotSame($message, $message = $message->withBody($body));
        $this->assertSame($body, $message->getBody());
    }

    public function testMessageAttributes()
    {
        $message = new HttpResponse();
        $this->assertnull($message->getAttribute('foo'));
        $this->assertEquals([], $message->getAttributes());
        
        $this->assertNotSame($message, $message = $message->withAttribute('foo', 'bar'));
        $this->assertEquals('bar', $message->getAttribute('foo'));
        $this->assertEquals('#', $message->getAttribute('bar', '#'));
        
        $this->assertNotSame($message, $message = $message->withAttributes([
            'bar' => '#'
        ]));
        $this->assertEquals('#', $message->getAttribute('bar'));
        
        $this->assertNotSame($message, $message = $message->withoutAttribute('foo'));
        $this->assertnull($message->getAttribute('foo'));
        $this->assertEquals('#', $message->getAttribute('bar'));
    }
}
