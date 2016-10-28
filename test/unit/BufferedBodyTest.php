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

use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Stream\ReadableMemoryStream;

/**
 * @covers \KoolKode\Async\Http\BufferedBody
 * @covers \KoolKode\Async\Http\BufferedBodyStream
 */
class BufferedBodyTest extends AsyncTestCase
{
    public function testCanAccessInMemoryData()
    {
        $body = new BufferedBody(new ReadableMemoryStream($payload = 'Hello World'));
        
        $this->assertTrue($body->isCached());
        $this->assertEquals(\strlen($payload), yield $body->getSize());
        $this->assertEquals($payload, yield $body->getContents());
        $this->assertEquals($payload, yield $body->getContents());
    }

    public function testCanAccessSizeOfTempData()
    {
        $body = new BufferedBody(new ReadableMemoryStream($payload = 'Hello World :)'), 7);
        
        $this->assertNull(yield $body->getSize());
        $this->assertEquals($payload, yield $body->getContents());
        $this->assertEquals(\strlen($payload), yield $body->getSize());
        $this->assertEquals($payload, yield $body->getContents());
    }
    
    public function testCanAccessContentsOfInMemoryData()
    {
        $body = new BufferedBody(new ReadableMemoryStream($payload = 'Hello World :)'));
    
        $this->assertEquals($payload, yield $body->getContents());
        $this->assertEquals(\strlen($payload), yield $body->getSize());
        $this->assertEquals($payload, yield $body->getContents());
    }

    public function testCanAccessContentsOfTempData()
    {
        $body = new BufferedBody(new ReadableMemoryStream($payload = 'Hello World :)'), 7);
        
        $this->assertEquals($payload, yield $body->getContents());
        $this->assertEquals(\strlen($payload), yield $body->getSize());
        $this->assertEquals($payload, yield $body->getContents());
    }

    public function testCanDiscardBody()
    {
        $bufferSize = 7;
        $body = new BufferedBody(new ReadableMemoryStream($payload = 'Hello World :)'), $bufferSize);
        
        $stream = yield $body->getReadableStream();
        
        try {
            $this->assertEquals(substr($payload, 0, 5), yield $stream->readBuffer(5));
        } finally {
            $stream->close();
        }
        
        $this->assertEquals(\strlen($payload) - $bufferSize, yield $body->discard());
        $this->assertEquals($payload, yield $body->getContents());
    }
}
