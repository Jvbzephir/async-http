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

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Http1\ResponseParser
 * @covers \KoolKode\Async\Http\Http1\MessageParser
 */
class ResponseParserTest extends AsyncTestCase
{
    public function testCanParseMinimalResponse()
    {
        $stream = new ReadableMemoryStream(implode('', [
            "HTTP/1.1 204 No Content\r\n",
            "\r\n"
        ]));
        
        $response = yield from (new ResponseParser())->parseResponse($stream);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals('1.1', $response->getProtocolVersion());
        $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
        $this->assertEquals('No Content', $response->getReasonPhrase());
    }
    
    public function testCanParseResponseWithPreDefinedStatusLine()
    {
        $stream = new ReadableMemoryStream(implode('', [
            "Content-Length: 0\r\n",
            "\r\n"
        ]));
        
        $response = yield from (new ResponseParser())->parseResponse($stream, "HTTP/1.0 303 See Other");
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals('1.0', $response->getProtocolVersion());
        $this->assertEquals(Http::SEE_OTHER, $response->getStatusCode());
        $this->assertEquals('See Other', $response->getReasonPhrase());
        $this->assertEquals('0', $response->getHeaderLine('Content-Length'));
    }
    
    public function testCanParseResponseHeader()
    {
        $stream = new ReadableMemoryStream(implode('', [
            "",
            "HTTP/1.0 200 OK\r\n",
            "Content-Length: 14\r\n",
            "\r\n",
            "Hello World :)"
        ]));
    
        $response = yield from (new ResponseParser())->parseResponse($stream);
    
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals('1.0', $response->getProtocolVersion());
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        
        $this->assertEquals('14', $response->getHeaderLine('Content-Length'));
        $this->assertEquals('Hello World :)', yield $response->getBody()->getContents());
    }
    
    public function testDetectsInvalidNumberOfNewLinesBeforeStatusLine()
    {
        $stream = new ReadableMemoryStream("\r\n\r\n\r\n\r\n");
        $parser = new ResponseParser();
        
        $this->expectException(StreamClosedException::class);
        
        yield from $parser->parseResponse($stream);
    }
    
    public function testDetectsInvalidStatusLine()
    {
        $stream = new ReadableMemoryStream("Foo\r\n");
        $parser = new ResponseParser();
    
        $this->expectException(StreamClosedException::class);
    
        yield from $parser->parseResponse($stream);
    }
    
    public function testDetectsMalformedHttpHeader()
    {
        $stream = new ReadableMemoryStream(implode('', [
            "HTTP/1.1 204 No Content\r\n",
            "Foobar\r\n"
        ]));
        $parser = new ResponseParser();
    
        $this->expectException(\RuntimeException::class);
    
        yield from $parser->parseResponse($stream);
    }
    
    public function testDetectsPrematureEndOfHeaders()
    {
        $stream = new ReadableMemoryStream("HTTP/1.1 204 No Content\r\n");
        $parser = new ResponseParser();
    
        $this->expectException(\RuntimeException::class);
    
        yield from $parser->parseResponse($stream);
    }
    
    public function testDetectsHeaderSizeTooLarge()
    {
        $stream = new ReadableMemoryStream(implode('', [
            "HTTP/1.1 204 No Content\r\n",
            "Message: Headers running out of space :P\r\n",
            "\r\n"
        ]));
        
        $parser = new ResponseParser();
        $parser->setMaxHeaderSize(30);
        
        $this->expectException(StatusException::class);
        
        yield from $parser->parseResponse($stream);
    }
}
