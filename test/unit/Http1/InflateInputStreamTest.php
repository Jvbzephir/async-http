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

class InflateInputStreamTest extends \PHPUnit_Framework_TestCase
{
    use AsyncTestTrait;
    
    protected function setUp()
    {
        parent::setUp();
        
        if (!InflateInputStream::isAvailable()) {
            return $this->markTestSkipped('zlib streaming decompression not available');
        }
    }
    
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
    public function testCanDecompressData(string $data, int $format, callable $encoder)
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () use ($data, $format, $encoder) {
            $in = yield from InflateInputStream::open(new StringInputStream($encoder($data)), $format);
            $decoded = yield from Stream::readContents($in);
            
            $this->assertTrue($in->eof());
            $this->assertEquals('0 bytes buffered', $in->__debugInfo()['buffer']);
            $this->assertEquals($data, $decoded);
        });
        
        $executor->run();
    }

    public function testRequiresValidCompressionType()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $this->expectException(\InvalidArgumentException::class);
            
            new InflateInputStream(new StringInputStream('Hello World'), '', 'FOO');
        });
        
        $executor->run();
    }
    
    public function testCannotReadFromClosedStream()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = yield from InflateInputStream::open(new StringInputStream(gzencode('Hello World')), InflateInputStream::GZIP);
            
            $this->assertFalse($in->eof());
            $this->assertEquals('Hello W', yield from Stream::readBuffer($in, 7, true));
            $this->assertFalse($in->eof());
            
            $in->close();
            $this->assertTrue($in->eof());
            
            $this->expectException(StreamClosedException::class);
            
            yield from $in->read();
        });
        
        $executor->run();
    }
    
    public function testCannotReadBeyondEndOfStream()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = yield from InflateInputStream::open(new StringInputStream(gzencode('Hello World')), InflateInputStream::GZIP);
            
            $this->assertFalse($in->eof());
            $this->assertEquals('Hello World', yield from Stream::readBuffer($in, 100));
            $this->assertTrue($in->eof());
            
            $this->expectException(StreamClosedException::class);
            
            yield from $in->read();
        });
        
        $executor->run();
    }
}
