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

use KoolKode\Async\ReadContents;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\FileBody
 */
class FileBodyTest extends AsyncTestCase
{
    public function testCanAccessBodyContents()
    {
        $body = new FileBody(__FILE__);
        
        $this->assertEquals(file_get_contents(__FILE__), yield $body->getContents());
    }

    public function testCanAccessBodyStream()
    {
        $body = new FileBody(__FILE__);
        $stream = yield $body->getReadableStream();
        
        $this->assertEquals(file_get_contents(__FILE__), yield new ReadContents($stream));
    }

    public function testCanAccessMetaData()
    {
        $body = new FileBody(__FILE__);
        
        $this->assertEquals(__FILE__, $body->getFile());
        $this->assertTrue($body->isCached());
        $this->assertEquals(filesize(__FILE__), yield $body->getSize());
    }
    
    public function testCanDiscardBody()
    {
        $body = new FileBody(__FILE__);
        
        $this->assertEquals(0, yield $body->discard());
        $this->assertEquals(0, yield $body->discard());
        
        $this->assertEquals(file_get_contents(__FILE__), yield $body->getContents());
    }
}
