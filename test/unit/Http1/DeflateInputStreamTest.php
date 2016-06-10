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

class DeflateInputStreamTest extends \PHPUnit_Framework_TestCase
{
    use AsyncTestTrait;
    
    protected function setUp()
    {
        parent::setUp();
        
        if (!DeflateInputStream::isAvailable()) {
            return $this->markTestSkipped('zlib streaming compression not available');
        }
    }
    
    public function provideCompressionData()
    {
        yield ['', DeflateInputStream::RAW, 'gzinflate'];
        yield ['', DeflateInputStream::DEFLATE, 'gzuncompress'];
        yield ['', DeflateInputStream::GZIP, 'gzdecode'];
        
        yield ['Hello World', DeflateInputStream::RAW, 'gzinflate'];
        yield ['Hello World', DeflateInputStream::DEFLATE, 'gzuncompress'];
        yield ['Hello World', DeflateInputStream::GZIP, 'gzdecode'];
        
        yield [file_get_contents(__FILE__), DeflateInputStream::RAW, 'gzinflate'];
        yield [file_get_contents(__FILE__), DeflateInputStream::DEFLATE, 'gzuncompress'];
        yield [file_get_contents(__FILE__), DeflateInputStream::GZIP, 'gzdecode'];
        
        yield [random_bytes(8191 * 32), DeflateInputStream::RAW, 'gzinflate'];
        yield [random_bytes(8191 * 32), DeflateInputStream::DEFLATE, 'gzuncompress'];
        yield [random_bytes(8191 * 32), DeflateInputStream::GZIP, 'gzdecode'];
    }

    /**
     * @dataProvider provideCompressionData
     */
    public function testCanCompressData(string $data, int $format, callable $decoder)
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () use ($data, $format, $decoder) {
            $in = yield from DeflateInputStream::open(new StringInputStream($data), $format);
            $encoded = yield from Stream::readContents($in);
            
            $this->assertTrue($in->eof());
            $this->assertEquals('0 bytes buffered', $in->__debugInfo()['buffer']);
            $this->assertEquals($data, $decoder($encoded));
        });
        
        $executor->run();
    }

    public function testRequiresValidCompressionType()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $this->expectException(\InvalidArgumentException::class);
            
            new DeflateInputStream(new StringInputStream('Hello World'), '', 'FOO');
        });
        
        $executor->run();
    }
    
    public function testCannotReadFromClosedStream()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = yield from DeflateInputStream::open(new StringInputStream('Hello World'), DeflateInputStream::GZIP);
            
            $this->assertFalse($in->eof());
            
            $in->close();
            $this->assertTrue($in->eof());
            
            $this->expectException(StreamClosedException::class);
            
            yield from $in->read();
        });
        
        $executor->run();
    }
    
    public function testCannotReadBeyondEndOfFile()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = yield from DeflateInputStream::open(new StringInputStream('Hello World'), DeflateInputStream::GZIP);
            
            $this->assertFalse($in->eof());
            
            $this->assertEquals('Hello World', gzdecode(yield from Stream::readBuffer($in, 100)));
            $this->assertTrue($in->eof());
            
            $this->expectException(StreamClosedException::class);
            
            yield from $in->read();
        });
        
        $executor->run();
    }
}
