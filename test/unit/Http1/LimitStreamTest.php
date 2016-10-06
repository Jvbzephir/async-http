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

use KoolKode\Async\ReadContents;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Http1\LimitStream
 */
class LimitStreamTest extends AsyncTestCase
{
    public function testReadToLimit()
    {
        $input = new ReadableMemoryStream('FOO & BAR');
        $stream = new LimitStream($input, 5);
        
        $this->assertEquals('FOO &', yield new ReadContents($stream, false));
        $this->assertnull(yield $stream->read());
        $this->assertFalse($input->isClosed());
        $this->assertEquals(5, $input->getOffset());
        
        $stream->close();
        $this->assertnull(yield $stream->read());
        $this->assertTrue($input->isClosed());
    }
    
    public function testLimitMustBePositive()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        new LimitStream(new ReadableMemoryStream(''), -4);
    }
}
