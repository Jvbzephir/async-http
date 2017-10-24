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
use KoolKode\Async\Deferred;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Http1\EntityStream
 */
class EntityStreamTest extends AsyncTestCase
{
    public function testDeferResolvesWithTrueOnEof(Context $context)
    {
        $defer = new Deferred($context);
        $stream = new EntityStream(new ReadableMemoryStream($message = 'Hello World :)'), $defer);
        
        $this->assertEquals($message, yield $stream->readBuffer($context, 100, false));
        $this->assertTrue(yield $defer->promise());
    }

    public function testDeferResolvesWithFalseOnClose(Context $context)
    {
        $defer = new Deferred($context);
        $stream = new EntityStream(new ReadableMemoryStream(''), $defer);
        $stream->close();
        
        $this->assertFalse(yield $defer->promise());
    }

    public function testDeferResolvesWithFalseOnDestruct(Context $context)
    {
        $defer = new Deferred($context);
        $stream = new EntityStream(new ReadableMemoryStream(''), $defer);
        unset($stream);
        
        $this->assertFalse(yield $defer->promise());
    }
}
