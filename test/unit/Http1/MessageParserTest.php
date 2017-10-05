<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Context;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Http1\MessageParser
 */
class MessageParserTest extends AsyncTestCase
{
    public function testCanParseResponse(Context $context)
    {
        $parser = new MessageParser();
        
        $response = yield from $parser->parseResponse($context, new ReadableMemoryStream(implode("\r\n", [
            'HTTP/1.1 200',
            'Connection: close',
            'Foo: bar',
            "\r\n"
        ])));
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals('1.1', $response->getProtocolVersion());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('', $response->getReasonPhrase());
        $this->assertEquals('bar', $response->getHeaderLine('Foo'));
    }
    
    public function testCanParseRequest(Context $context)
    {
        $parser = new MessageParser();
        
        $request = yield from $parser->parseRequest($context, new ReadableMemoryStream(implode("\r\n", [
            'GET /form HTTP/1.0',
            'Connection: keep-alive',
            'Host: example.com',
            'Foo: bar',
            "\r\n"
        ])));
        
        $this->assertTrue($request instanceof HttpRequest);
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('/form', $request->getRequestTarget());
        $this->assertEquals('http://example.com/form', (string) $request->getUri());
        $this->assertEquals('1.0', $request->getProtocolVersion());
        $this->assertEquals('bar', $request->getHeaderLine('Foo'));
    }
    
    public function testCanParseFullUriTarget(Context $context)
    {
        $parser = new MessageParser();
        
        $request = yield from $parser->parseRequest($context, new ReadableMemoryStream(implode("\r\n", [
            'CONNECT https://foo.bar/test HTTP/1.1',
            'Host: proxy.test',
            "\r\n"
        ])));
        
        $this->assertTrue($request instanceof HttpRequest);
        $this->assertEquals('CONNECT', $request->getMethod());
        $this->assertEquals('https://foo.bar/test', $request->getRequestTarget());
        $this->assertEquals('http://proxy.test/', (string) $request->getUri());
        $this->assertEquals('1.1', $request->getProtocolVersion());
        $this->assertEquals('proxy.test', $request->getHeaderLine('Host'));
    }
    
    public function testCanParseAsteriskTarget(Context $context)
    {
        $parser = new MessageParser();
        
        $request = yield from $parser->parseRequest($context, new ReadableMemoryStream(implode("\r\n", [
            'OPTIONS * HTTP/1.1',
            'Connection: close',
            "\r\n"
        ])));
        
        $this->assertTrue($request instanceof HttpRequest);
        $this->assertEquals('OPTIONS', $request->getMethod());
        $this->assertEquals('*', $request->getRequestTarget());
        $this->assertEquals('*', (string) $request->getUri());
        $this->assertEquals('1.1', $request->getProtocolVersion());
    }
    
    public function testDetectsMissingRequestLine(Context $context)
    {
        $parser = new MessageParser();
        
        $this->expectException(StreamClosedException::class);
        
        yield from $parser->parseRequest($context, new ReadableMemoryStream());
    }

    public function testDetectsMissingResponseLine(Context $context)
    {
        $parser = new MessageParser();
        
        $this->expectException(StreamClosedException::class);
        
        yield from $parser->parseResponse($context, new ReadableMemoryStream());
    }
    
    public function testDetectsMissingHeaders(Context $context)
    {
        $parser = new MessageParser();
        
        $this->expectException(StreamClosedException::class);
        
        yield from $parser->parseResponse($context, new ReadableMemoryStream("HTTP/1.0 200\r\n"));
    }
    
    public function testDetectsInvalidRequestLine(Context $context)
    {
        $parser = new MessageParser();
        
        $this->expectException(\RuntimeException::class);
        
        yield from $parser->parseRequest($context, new ReadableMemoryStream("GET HTTP/1.0\r\n"));
    }
    
    public function testDetectsInvalidResponseLine(Context $context)
    {
        $parser = new MessageParser();
        
        $this->expectException(\RuntimeException::class);
        
        yield from $parser->parseResponse($context, new ReadableMemoryStream("HTTP/1.2 200\r\n"));
    }
    
    public function testLenghtEncodedBodyStream(Context $context)
    {
        $parser = new MessageParser();
        $stream = new ReadableMemoryStream('foobar');
        
        $response = new HttpResponse(Http::OK, [
            'Content-Length' => '3'
        ]);
        
        $body = $parser->parseBodyStream($response, $stream);
        
        $this->assertInstanceOf(LimitStream::class, $body);
        $this->assertEquals('foo', yield $body->read($context));
    }
    
    public function testChunkEncodedBodyStream(Context $context)
    {
        $parser = new MessageParser();
        $stream = new ReadableMemoryStream("6\r\nfoobar\r\n0\r\n\r\n");
        
        $response = new HttpResponse(Http::OK, [
            'Transfer-Encoding' => 'chunked'
        ]);
        
        $body = $parser->parseBodyStream($response, $stream);
        
        $this->assertInstanceOf(ChunkDecodedStream::class, $body);
        $this->assertEquals('foobar', yield $body->read($context));
    }
    
    public function testDetectsCloseStream(Context $context)
    {
        $parser = new MessageParser();
        $stream = new ReadableMemoryStream("foo");
        
        $body = $parser->parseBodyStream(new HttpResponse(), $stream);
        
        $this->assertSame($stream, $body);
        $this->assertEquals('foo', yield $body->read($context));
    }
}
