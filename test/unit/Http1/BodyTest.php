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

use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\WritableMemoryStream;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Http\Http;

/**
 * @covers \KoolKode\Async\Http\Http1\Body
 * @covers \KoolKode\Async\Http\Http1\EntityStream
 */
class BodyTest extends AsyncTestCase
{
    public function testUnspecifiedContentLength()
    {
        $input = new ReadableMemoryStream();
        $body = new Body($input);
        
        $this->assertFalse($body->isCached());
        $this->assertEquals('', yield $body->getContents());
        $this->assertTrue($input->isClosed());
    }
    
    public function testLengthEncodedData()
    {
        $input = new ReadableMemoryStream('Test');
        
        $body = new Body($input);
        $body->setCascadeClose(false);
        $body->setLength(3);
        
        $stream = yield $body->getReadableStream();
        
        $this->assertTrue($stream instanceof EntityStream);
        $this->assertEquals(3, yield $body->getSize());
        
        $resolved = false;
        
        $stream->getAwaitable()->when(function ($e = null, $v = null) use (& $resolved) {
            if (!$e) {
                $resolved = true;
            }
        });
        
        $this->assertFalse($resolved);
        
        try {
            $this->assertEquals('Tes', yield $stream->read());
        } finally {
            $stream->close();
        }
        
        $this->assertFalse($input->isClosed());
        $this->assertTrue($resolved);
    }
    
    public function testDetectsInvalidContentLength()
    {
        $body = new Body(new ReadableMemoryStream());
        
        $this->expectException(\InvalidArgumentException::class);
        
        $body->setLength(-1);
    }
    
    public function testChunkEncodedData()
    {
        $input = new ReadableMemoryStream("4\r\nTest\r\n0\r\n\r\n");
        
        $body = new Body($input);
        $body->setChunkEncoded(true);
        
        $stream = yield $body->getReadableStream();
        
        $this->assertnull(yield $body->getSize());
        
        try {
            $this->assertEquals('Test', yield $stream->read());
        } finally {
            $stream->close();
        }
        
        $this->assertTrue($input->isClosed());
    }
    
    public function testExpectContinue()
    {
        $expect = new WritableMemoryStream();
        
        $body = new Body(new ReadableMemoryStream('Foo'));
        $body->setLength(3);
        $body->setExpectContinue($expect);
        
        $stream = yield $body->getReadableStream();
        
        try {
            $this->assertEquals('Foo', yield new ReadContents($stream));
            $this->assertEquals("HTTP/1.1 100 Continue\r\n", $expect->getContents());
        } finally {
            $stream->close();
        }
    }
    
    public function testLengthEncodedBodyFromMessage()
    {
        $message = new HttpResponse(Http::OK, [
            'Content-Length' => '4'
        ]);
        
        $body = Body::fromMessage(new ReadableMemoryStream('Test'), $message);
        
        $this->assertEquals('Test', yield $body->getContents());
    }
    
    public function testInvalidContentLengthFromMessage()
    {
        $message = new HttpResponse(Http::OK, [
            'Content-Length' => 'x'
        ]);
        
        $this->expectException(StatusException::class);
        
        Body::fromMessage(new ReadableMemoryStream('Test'), $message);
    }
    
    public function testChunkEncodedBodyFromMessage()
    {
        $message = new HttpResponse(Http::OK, [
            'Transfer-Encoding' => 'chunked'
        ]);
        
        $body = Body::fromMessage(new ReadableMemoryStream("4\r\nTest\r\n0\r\n\r\n"), $message);
        
        $this->assertEquals('Test', yield $body->getContents());
    }
    
    public function testInvalidTransferEncodingFromMessage()
    {
        $message = new HttpResponse(Http::OK, [
            'Transfer-Encoding' => 'x'
        ]);
    
        $this->expectException(StatusException::class);
    
        Body::fromMessage(new ReadableMemoryStream("4\r\nTest\r\n0\r\n\r\n"), $message);
    }
    
    public function testSupportsEOFUsingMessage()
    {
        $input = new ReadableMemoryStream('Hi');
        
        $message = new HttpResponse(Http::OK, [
            'Connection' => 'close'
        ], new StreamBody($input));
        
        $stream = yield Body::fromMessage($input, $message)->getReadableStream();
        
        try {
            $this->assertEquals('Hi', yield new ReadContents($stream));
        } finally {
            $stream->close();
        }
        
        $this->assertTrue($input->isClosed());
    }

    public function testDecoratesStreamWhenNotCascadingClose()
    {
        $input = new ReadableMemoryStream('Hi');
        
        $body = new Body($input, true);
        $body->setCascadeClose(false);
        
        $stream = yield $body->getReadableStream();
        
        try {
            $this->assertEquals('Hi', yield new ReadContents($stream));
        } finally {
            $stream->close();
        }
        
        $this->assertNull(yield $input->read());
        $this->assertFalse($input->isClosed());
    }
    
    public function testCanDiscardBodyBeforeContinue()
    {
        $body = new Body(new ReadableMemoryStream('Hello World'), true);
        $body->setExpectContinue($expect = new WritableMemoryStream());
        
        $this->assertEquals(0, yield $body->discard());
        $this->assertEquals('', yield $body->getContents());
        
        // Ensure expect continue is skipped...
        $this->assertEquals('', $expect->getContents());
    }
    
    public function testCanDiscardBody()
    {
        $body = new Body(new ReadableMemoryStream('Hello World'), true);
        $body->setExpectContinue($expect = new WritableMemoryStream());
    
        $this->assertEquals('H', yield (yield $body->getReadableStream())->read(1));
        
        $this->assertEquals(10, yield $body->discard());
        $this->assertEquals('', yield $body->getContents());
    
        $this->assertEquals(0, yield $body->discard());
        $this->assertEquals('', yield $body->getContents());
        
        $this->assertEquals("HTTP/1.1 100 Continue\r\n", $expect->getContents());
    }
}
