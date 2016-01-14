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
    
    public function provideCompressionData()
    {
        yield ['', InflateInputStream::RAW, 'gzdeflate'];
        yield ['', InflateInputStream::DEFLATE, 'gzcompress'];
        yield ['', InflateInputStream::GZIP, 'gzencode'];
        
        yield ['Hello World', InflateInputStream::RAW, 'gzdeflate'];
        yield ['Hello World', InflateInputStream::DEFLATE, 'gzcompress'];
        yield ['Hello World', InflateInputStream::GZIP, 'gzencode'];
        
        yield [file_get_contents(__FILE__), InflateInputStream::RAW, 'gzdeflate'];
        yield [file_get_contents(__FILE__), InflateInputStream::DEFLATE, 'gzcompress'];
        yield [file_get_contents(__FILE__), InflateInputStream::GZIP, 'gzencode'];
        
        yield [random_bytes(8192 * 32), InflateInputStream::RAW, 'gzdeflate'];
        yield [random_bytes(8192 * 32), InflateInputStream::DEFLATE, 'gzcompress'];
        yield [random_bytes(8192 * 32), InflateInputStream::GZIP, 'gzencode'];
    }

    /**
     * @dataProvider provideCompressionData
     */
    public function testCanDecodeCompressedData(string $data, int $format, callable $encoder)
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () use ($data, $format, $encoder) {
            $in = yield from InflateInputStream::open(yield tempStream($encoder($data)), $format);
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
            new InflateInputStream(yield tempStream('Hello World'), '', 'FOO');
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
            $in = yield from InflateInputStream::open(yield tempStream(gzencode('Hello World')), InflateInputStream::GZIP);
            
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
            $in = yield from InflateInputStream::open(yield tempStream(gzencode('Hello World')), InflateInputStream::GZIP);
            
            $this->assertFalse($in->eof());
            $this->assertEquals('Hello World', yield readBuffer($in, 100));
            $this->assertTrue($in->eof());
            
            yield from $in->read();
        });
        
        $executor->run();
    }
}
