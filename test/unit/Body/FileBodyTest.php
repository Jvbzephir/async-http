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

use KoolKode\Async\Filesystem\Filesystem;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Success;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Body\FileBody
 */
class FileBodyTest extends AsyncTestCase
{
    public function testCanAccessBodyContents()
    {
        $body = new FileBody(__FILE__);
        
        $this->assertEquals(file_get_contents(__FILE__), yield $body->getContents());
    }

    public function testCanInjectFilesystem()
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects($this->once())->method('size')->with(__FILE__)->will($this->returnValue(new Success(1337)));
        
        $body = new FileBody(__FILE__);
        $body->setFilesystem($filesystem);
        
        $this->assertEquals(1337, yield $body->getSize());
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
