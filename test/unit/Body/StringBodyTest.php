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
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Body\StringBody
 */
class StringBodyTest extends AsyncTestCase
{
    public function testCanAccessBodyContents(Context $context)
    {
        $body = new StringBody($contents = 'Hello World');
        
        $this->assertEquals($contents, (string) $body);
        $this->assertEquals($contents, yield $body->getContents($context));
        
        $stream = yield $body->getReadableStream($context);
        $this->assertEquals($contents, yield $stream->readBuffer($context, 1000, false));
    }
    
    public function testCanAccessMetaData(Context $context)
    {
        $body = new StringBody($contents = 'Hello World');
        
        $this->assertTrue($body->isCached());
        $this->assertEquals(strlen($contents), yield $body->getSize($context));
    }
    
    public function testCanDiscardBody(Context $context)
    {
        $body = new StringBody($contents = 'Test 1');
        
        $this->assertEquals(0, yield $body->discard($context));
        $this->assertEquals(0, yield $body->discard($context));
        
        $this->assertEquals($contents, yield $body->getContents($context));
    }
}
