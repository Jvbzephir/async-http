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
 * @covers \KoolKode\Async\Http\Body\FileBody
 */
class FileBodyTest extends AsyncTestCase
{
    public function testCanAccessBodyContents(Context $context)
    {
        $body = new FileBody(__FILE__);
        
        $this->assertEquals(file_get_contents(__FILE__), yield $body->getContents($context));
        $this->assertEquals(file_get_contents(__FILE__), yield $body->getContents($context));
    }

    public function testCanInjectFilesystem(Context $context)
    {
        $body = new FileBody(__FILE__);
        
        $this->assertEquals(filesize(__FILE__), yield $body->getSize($context));
    }

    public function testCanAccessBodyStream(Context $context)
    {
        $body = new FileBody(__FILE__);
        $stream = yield $body->getReadableStream($context);
        
        $this->assertEquals(file_get_contents(__FILE__, false, null, 0, 500), yield $stream->readBuffer($context, 500));
    }

    public function testCanAccessMetaData(Context $context)
    {
        $body = new FileBody(__FILE__);
        
        $this->assertEquals(__FILE__, $body->getFile());
        $this->assertTrue($body->isCached());
        $this->assertEquals(filesize(__FILE__), yield $body->getSize($context));
    }
    
    public function testCanDiscardBody(Context $context)
    {
        $body = new FileBody(__FILE__);
        
        $this->assertEquals(0, yield $body->discard($context));
        $this->assertEquals(0, yield $body->discard($context));
        
        $this->assertEquals(file_get_contents(__FILE__), yield $body->getContents($context));
    }
}
