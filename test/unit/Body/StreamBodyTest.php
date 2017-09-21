<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Body;

use KoolKode\Async\Context;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Body\StreamBody
 */
class StreamBodyTest extends AsyncTestCase
{
    public function testCanAccessBodyContents(Context $context)
    {
        $body = new StreamBody($input = new ReadableMemoryStream('Hello World'));
        
        $this->assertEquals($input->getContents(), yield $body->getContents($context));
        $this->assertTrue($input->isClosed());
    }

    public function testCanAccessBodyStream(Context $context)
    {
        $body = new StreamBody($input = new ReadableMemoryStream('Hello World'));
        
        $stream = yield $body->getReadableStream($context);
        $buffer = '';
        
        try {
            while (null !== ($chunk = yield $stream->read($context))) {
                $buffer .= $chunk;
            }
        } finally {
            $stream->close();
        }
        
        $this->assertSame($input, $stream);
        $this->assertEquals($input->getContents(), $buffer);
    }

    public function testCanAccessMetaData(Context $context)
    {
        $body = new StreamBody(new ReadableMemoryStream('Hello World'));
        
        $this->assertFalse($body->isCached());
        $this->assertnull(yield $body->getSize($context));
    }

    public function testCanDiscardBody(Context $context)
    {
        $body = new StreamBody(new ReadableMemoryStream('Hello World'));
        
        $this->assertEquals(11, yield $body->discard($context));
        
        $this->expectException(StreamClosedException::class);
        
        yield $body->getContents($context);
    }
}
