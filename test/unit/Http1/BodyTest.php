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

use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\WritableMemoryStream;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Http\StreamBody;

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
    
    public function testDoesNotModifyMessage()
    {
        $body = new Body(new ReadableMemoryStream());
        $message = new HttpResponse();
        
        $this->assertSame($message, $body->prepareMessage($message));
    }
    
    public function testDeflateEncodedBody()
    {
        if (!function_exists('inflate_init')) {
            return $this->markTestSkipped('Test requires incremental zlib compression');
        }
    
        $input = new ReadableMemoryStream(gzcompress('Hello'));
    
        $body = new Body($input);
        $body->setLength($input->getSize());
        $body->setCompression(Body::COMPRESSION_DEFLATE);
    
        $this->assertEquals('Hello', yield $body->getContents());
    }

    public function testGzipEncodedBody()
    {
        if (!function_exists('inflate_init')) {
            return $this->markTestSkipped('Test requires incremental zlib compression');
        }
        
        $input = new ReadableMemoryStream(gzencode('Hello'));
        
        $body = new Body($input);
        $body->setLength($input->getSize());
        $body->setCompression(Body::COMPRESSION_GZIP);
        
        $this->assertEquals('Hello', yield $body->getContents());
    }
    
    public function testDetectsInvalidCompressionEncoding()
    {
        $body = new Body(new ReadableMemoryStream());
        
        $this->expectException(\InvalidArgumentException::class);
        
        $body->setCompression('foo');
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
        $message = new HttpResponse();
        $message = $message->withHeader('Content-Length', 4);
        
        $body = Body::fromMessage(new ReadableMemoryStream('Test'), $message);
        
        $this->assertEquals('Test', yield $body->getContents());
    }
    
    public function testInvalidContentLengthFromMessage()
    {
        $message = new HttpResponse();
        $message = $message->withHeader('Content-Length', 'x');
        
        $this->expectException(StatusException::class);
        
        Body::fromMessage(new ReadableMemoryStream('Test'), $message);
    }
    
    public function testChunkEncodedBodyFromMessage()
    {
        $message = new HttpResponse();
        $message = $message->withHeader('Transfer-Encoding', 'chunked');
        
        $body = Body::fromMessage(new ReadableMemoryStream("4\r\nTest\r\n0\r\n\r\n"), $message);
        
        $this->assertEquals('Test', yield $body->getContents());
    }
    
    public function testInvalidTransferEncodingFromMessage()
    {
        $message = new HttpResponse();
        $message = $message->withHeader('Transfer-Encoding', 'x');
    
        $this->expectException(StatusException::class);
    
        Body::fromMessage(new ReadableMemoryStream("4\r\nTest\r\n0\r\n\r\n"), $message);
    }
    
    public function testDetectsCompressionFromMessage()
    {
        if (!function_exists('inflate_init')) {
            return $this->markTestSkipped('Test requires incremental zlib compression');
        }
        
        $input = new ReadableMemoryStream(gzencode('Test'));
        
        $message = new HttpResponse();
        $message = $message->withHeader('Content-Length', $input->getSize());
        $message = $message->withHeader('Content-Encoding', 'gzip');
        
        $this->assertEquals('Test', yield Body::fromMessage($input, $message)->getContents());
    }
    
    public function testDetectsCompressedRequestBody()
    {
        $message = new HttpRequest('http://test.me/');
        $message = $message->withHeader('Content-Encoding', 'gzip');
        
        $this->expectException(StatusException::class);
        
        Body::fromMessage(new ReadableMemoryStream(), $message);
    }
    
    public function testSupportsEOFUsingMessage()
    {
        $input = new ReadableMemoryStream('Hi');
        
        $message = new HttpResponse();
        $message = $message->withHeader('Connection', 'close');
        $message = $message->withBody(new StreamBody($input));
        
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
    
    public function testDecoratesCompressedStreamWhenNotCascadingClose()
    {
        $input = new ReadableMemoryStream(gzencode('Hi'));
    
        $body = new Body($input, true);
        $body->setCascadeClose(false);
        $body->setCompression(Body::COMPRESSION_GZIP);
    
        $stream = yield $body->getReadableStream();
    
        try {
            $this->assertEquals('Hi', yield new ReadContents($stream));
        } finally {
            $stream->close();
        }
    
        $this->assertNull(yield $input->read());
        $this->assertFalse($input->isClosed());
    }
    
    public function testCanDiscardBody()
    {
        $body = new Body(new ReadableMemoryStream('Hello World'), true);
        $body->setExpectContinue($expect = new WritableMemoryStream());
        
        $this->assertEquals(11, yield $body->discard());
        $this->assertEquals('', yield $body->getContents());
        
        $this->assertEquals(0, yield $body->discard());
        $this->assertEquals('', yield $body->getContents());
    
        // Ensure expect continue is skipped...
        $this->assertEquals('', $expect->getContents());
    }
}
