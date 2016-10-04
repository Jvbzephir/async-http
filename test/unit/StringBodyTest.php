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
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\StringBody
 */
class StringBodyTest extends AsyncTestCase
{
    public function testCanAccessBodyContents()
    {
        $contents = 'Hello World';
        $body = new StringBody($contents);
        
        $this->assertEquals($contents, (string) $body);
        $this->assertEquals($contents, yield $body->getContents());
        $this->assertEquals($contents, yield new ReadContents(yield $body->getReadableStream()));
    }
    
    public function testCanAccessMetaData()
    {
        $contents = 'Hello World';
        $body = new StringBody($contents);
        $message = new HttpResponse();
        
        $this->assertTrue($body->isCached());
        $this->assertEquals(strlen($contents), yield $body->getSize());
        $this->assertSame($message, $body->prepareMessage($message));
    }
}
