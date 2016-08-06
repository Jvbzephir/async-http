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

class ChunkDecodedInputStreamTest extends \PHPUnit_Framework_TestCase
{
    use AsyncTestTrait;
    
    protected function chunkEncode(string $data, int $len): string
    {
        $chunked = '';
        
        foreach (str_split($data, $len) as $chunk) {
            $chunked .= sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk);
        }
        
        return $chunked . "0\r\n\r\n";
    }
    
    public function provideChunkedContents()
    {
        $data = file_get_contents(__FILE__);
        
        yield ['', 8192];
        yield [$data, 1];
        yield [$data, 5];
        yield [$data, 8];
        yield [$data, 16];
        yield [$data, 256];
        yield [$data, 8192];
        yield [$data, 8192 * 32];
    }

    /**
     * @dataProvider provideChunkedContents
     */
    public function testCanDecodeChunkedData(string $data, int $len)
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () use ($data, $len) {
            $in = yield from ChunkDecodedInputStream::open(new StringInputStream($this->chunkEncode($data, $len)));
            
            if ($data !== '') {
                $this->assertFalse($in->eof());
            }
            
            $decoded = yield from Stream::readContents($in);
            $this->assertTrue($in->eof());
            $this->assertEquals($data, $decoded);
            $this->assertEquals('0 bytes buffered', $in->__debugInfo()['buffer']);
        });
        
        $executor->run();
    }
    
    public function testDetectsInvalidFirstChunk()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $this->expectException(\RuntimeException::class);
            
            yield from ChunkDecodedInputStream::open(new StringInputStream("FOOBAR\r\nBAZ!\r\n"));
        });
        
        $executor->run();
    }
    
    public function testDetectsFirstChunkTooBig()
    {
        $executor = $this->createExecutor();
    
        $executor->runCallback(function () {
            $this->expectException(\RuntimeException::class);
            
            yield from ChunkDecodedInputStream::open(new StringInputStream("FFFFFFFF\r\n"));
        });
    
        $executor->run();
    }
    
    public function testDetectsReadFromClosedStream()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = yield from ChunkDecodedInputStream::open(new StringInputStream($this->chunkEncode('Hello World this is some data...', 10)));
            $this->assertFalse($in->eof());
            
            $in->close();
            $this->assertTrue($in->eof());
            
            $this->expectException(StreamClosedException::class);
            
            yield from $in->read();
        });
        
        $executor->run();
    }
    
    public function testDetectsReadFromStreamAfterEof()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = yield from ChunkDecodedInputStream::open(new StringInputStream($this->chunkEncode('H', 10)));
            $this->assertFalse($in->eof());
            
            $this->assertEquals('H', yield from $in->read());
            $this->assertTrue($in->eof());
            
            $this->expectException(StreamClosedException::class);
            
            yield from $in->read();
        });
        
        $executor->run();
    }
    
    public function testDetectsMalformedChunkHeaderWithinStream()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = yield from ChunkDecodedInputStream::open(new StringInputStream("3;foo\r\nFOO\r\nBAR\r\n"));
            $this->assertFalse($in->eof());
            
            $this->expectException(\RuntimeException::class);
            
            yield from Stream::readContents($in);
        });
        
        $executor->run();
    }
    
    public function testDetectsChunkWithinStreamTooBig()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = yield from ChunkDecodedInputStream::open(new StringInputStream("3;foo=\"bar\"\r\nFOO\r\nFFFFFFFF\r\n"));
            $this->assertFalse($in->eof());
            
            $this->expectException(\RuntimeException::class);
            
            yield from Stream::readContents($in);
        });
    
        $executor->run();
    }
}
