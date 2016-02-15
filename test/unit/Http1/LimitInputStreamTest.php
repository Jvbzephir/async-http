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

use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Test\AsyncTrait;

use function KoolKode\Async\Stream\tempStream;
use function KoolKode\Async\Stream\readBuffer;

class LimitInputStreamTest extends \PHPUnit_Framework_TestCase
{
    use AsyncTrait;
    
    public function testCanDecodeCompressedData()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $data = 'FOO & BAR';
            $in = new LimitInputStream(yield tempStream($data), 5);
            
            $this->assertFalse($in->eof());
            $this->assertEquals('FOO &', yield from $this->readContents($in));
            $this->assertTrue($in->eof());
        });
        
        $executor->run();
    }
    
    public function testLimitMustBePositive()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $this->expectException(\InvalidArgumentException::class);
            
            new LimitInputStream(yield tempStream(), -4);
        });
        
        $executor->run();
    }
    
    public function testCannotReadBeyondLimit()
    {
        $executor = $this->createExecutor();
    
        $executor->runCallback(function () {
            $in = new LimitInputStream(yield tempStream('ABCDEF'), 3);
            yield readBuffer($in, 100);
            
            $this->assertTrue($in->eof());
            
            $this->expectException(StreamClosedException::class);
            
            yield from $in->read();
        });
    
        $executor->run();
    }
    
    public function testCannotReadFromClosedStream()
    {
        $executor = $this->createExecutor();
    
        $executor->runCallback(function () {
            $in = new LimitInputStream(yield tempStream('ABCDEF'), 3);
            $in->close();
            
            $this->expectException(StreamClosedException::class);
            
            yield from $in->read();
        });
    
        $executor->run();
    }
}
