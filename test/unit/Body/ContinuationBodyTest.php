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
 * @covers \KoolKode\Async\Http\Body\ContinuationBody
 */
class ContinuationBodyTest extends AsyncTestCase
{
    public function testCanAccessBodyContents(Context $context)
    {
        $done = false;
        $body = new ContinuationBody($input = new ReadableMemoryStream('Hello World'), function (Context $context, $stream) use (& $done) {
            yield null;
            
            $done = true;
            
            return $stream;
        });
        
        $this->assertFalse($done);
        $this->assertEquals($input->getContents(), yield $body->getContents($context));
        $this->assertTrue($done);
        $this->assertTrue($input->isClosed());
    }

    public function testCanDiscardBody(Context $context)
    {
        $done = false;
        $body = new ContinuationBody($input = new ReadableMemoryStream('Hello World'), function (Context $context, $stream) use (& $done) {
            yield null;
            
            $done = true;
            
            return $stream;
        });
        
        $this->assertFalse($done);
        $this->assertEquals(0, yield $body->discard($context));
        $this->assertFalse($done);
        $this->assertFalse($input->isClosed());
    }

    public function testCannotAccessStreamTwice(Context $context)
    {
        $body = new ContinuationBody($input = new ReadableMemoryStream('Hello World'), function (Context $context, $stream) {
            yield null;
            
            return $stream;
        });
        
        $this->assertEquals(0, yield $body->discard($context));
        $this->assertEquals(0, yield $body->discard($context));
        
        $this->expectException(StreamClosedException::class);
        
        yield $body->getContents($context);
    }
}
