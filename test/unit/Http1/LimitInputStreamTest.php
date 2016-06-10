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

use KoolKode\Async\Stream\Stream;
use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Stream\StringInputStream;
use KoolKode\Async\Util\AsyncTestTrait;

class LimitInputStreamTest extends \PHPUnit_Framework_TestCase
{
    use AsyncTestTrait;
    
    public function testCanDecodeCompressedData()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $data = 'FOO & BAR';
            $in = new LimitInputStream(new StringInputStream($data), 5);
            
            $this->assertFalse($in->eof());
            $this->assertEquals('FOO &', yield from Stream::readContents($in));
            $this->assertTrue($in->eof());
        });
        
        $executor->run();
    }
    
    public function testLimitMustBePositive()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $this->expectException(\InvalidArgumentException::class);
            
            new LimitInputStream(new StringInputStream(), -4);
        });
        
        $executor->run();
    }
    
    public function testCannotReadBeyondLimit()
    {
        $executor = $this->createExecutor();
    
        $executor->runCallback(function () {
            $in = new LimitInputStream(new StringInputStream('ABCDEF'), 3);
            
            yield from Stream::readBuffer($in, 100);
            
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
            $in = new LimitInputStream(new StringInputStream('ABCDEF'), 3);
            $in->close();
            
            $this->expectException(StreamClosedException::class);
            
            yield from $in->read();
        });
    
        $executor->run();
    }
}
