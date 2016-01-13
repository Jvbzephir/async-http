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

use KoolKode\Async\Test\AsyncTrait;

use function KoolKode\Async\readBuffer;
use function KoolKode\Async\tempStream;

class InflateInputStreamTest extends \PHPUnit_Framework_TestCase
{
    use AsyncTrait;
    
    public function testCanDecodeCompressedData()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $data = file_get_contents(__FILE__);
            $in = new InflateInputStream(yield tempStream(gzencode($data)), InflateInputStream::GZIP);
            $decoded = yield from $this->readContents($in);
            
            $this->assertTrue($in->eof());
            $this->assertEquals('0 bytes buffered', $in->__debugInfo()['buffer']);
            $this->assertEquals($data, $decoded);
        });
        
        $executor->run();
    }
    
    /**
     * @expectedException \InvalidArgumentException
     */
    public function testRequiresValidCompressionType()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            new InflateInputStream(yield tempStream('Hello World'), 'FOO');
        });
        
        $executor->run();
    }
    
    /**
     * @expectedException \KoolKode\Async\Stream\SocketClosedException
     */
    public function testCannotReadFromClosedStream()
    {
        $executor = $this->createExecutor();
    
        $executor->runCallback(function () {
            $in = new InflateInputStream(yield tempStream(gzencode('Hello World')), InflateInputStream::GZIP);
    
            $this->assertFalse($in->eof());
            $this->assertEquals('Hello W', yield readBuffer($in, 7));
            $this->assertFalse($in->eof());
            
            $in->close();
            $this->assertTrue($in->eof());
            
            yield from $in->read();
        });
    
        $executor->run();
    }

    /**
     * @expectedException \KoolKode\Async\Stream\SocketClosedException
     */
    public function testRemainingBufferCanBeReadWhenFinished()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = new InflateInputStream(yield tempStream(gzencode('Hello World')), InflateInputStream::GZIP);
            
            $this->assertFalse($in->eof());
            $this->assertEquals('Hello World', yield readBuffer($in, 100));
            $this->assertTrue($in->eof());
            
            yield from $in->read();
        });
        
        $executor->run();
    }
}
