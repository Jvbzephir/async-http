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
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Http1\RequestParser
 * @covers \KoolKode\Async\Http\Http1\MessageParser
 */
class RequestParserTest extends AsyncTestCase
{
    public function testCanParseMinimalResponse()
    {
        $stream = new ReadableMemoryStream(implode('', [
            "GET /api?foo=bar HTTP/1.0\r\n",
            "\r\n"
        ]));
        
        $request = yield from (new RequestParser())->parseRequest($stream);
        
        $this->assertTrue($request instanceof HttpRequest);
        $this->assertEquals(Http::GET, $request->getMethod());
        $this->assertEquals('/api?foo=bar', $request->getRequestTarget());
        $this->assertEquals('1.0', $request->getProtocolVersion());
    }
    
    public function testDetectsInvalidNumberOfNewLinesBeforeRequestLine()
    {
        $stream = new ReadableMemoryStream("\r\n\r\n\r\n\r\n");
        $parser = new RequestParser();
    
        $this->expectException(StreamClosedException::class);
    
        yield from $parser->parseRequest($stream);
    }
    
    public function testDetectsInvalidRequestLine()
    {
        $stream = new ReadableMemoryStream("FOO /bar HTTP/1.x\r\n");
        $parser = new RequestParser();
    
        $this->expectException(StreamClosedException::class);
    
        yield from $parser->parseRequest($stream);
    }
}
