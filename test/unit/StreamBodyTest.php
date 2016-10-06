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

use KoolKode\Async\ReadContents;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\StreamBody
 */
class StreamBodyTest extends AsyncTestCase
{
    public function testCanAccessBodyContents()
    {
        $body = new StreamBody($input = new ReadableMemoryStream('Hello World'));
        
        $this->assertEquals($input->getContents(), yield $body->getContents());
        $this->assertTrue($input->isClosed());
    }

    public function testCanAccessBodyStream()
    {
        $body = new StreamBody($input = new ReadableMemoryStream('Hello World'));
        $stream = yield $body->getReadableStream();
        
        $this->assertSame($input, $stream);
        $this->assertEquals($input->getContents(), yield new ReadContents($stream));
    }

    public function testCanAccessMetaData()
    {
        $body = new StreamBody(new ReadableMemoryStream('Hello World'));
        $message = new HttpResponse();
        
        $this->assertFalse($body->isCached());
        $this->assertnull(yield $body->getSize());
        $this->assertSame($message, $body->prepareMessage($message));
    }
}
