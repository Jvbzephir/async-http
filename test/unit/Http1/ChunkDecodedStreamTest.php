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

use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\StreamException;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\ReadContents;

/**
 * @covers \KoolKode\Async\Http\Http1\ChunkDecodedStream
 */
class ChunkDecodedStreamTest extends AsyncTestCase
{
    protected function chunkEncode(string $data, int $len): string
    {
        $chunked = '';
        
        foreach (str_split($data, $len) as $chunk) {
            $chunked .= sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk);
        }
        
        return $chunked . "0\r\n\r\n";
    }
    
    public function testDetectsEmptyInput()
    {
        $stream = new ChunkDecodedStream(new ReadableMemoryStream());
        
        $this->assertnull(yield $stream->read());
    }
    
    public function testDetectsEmptyChunkedData()
    {
        $stream = new ChunkDecodedStream(new ReadableMemoryStream("0\r\n\r\n"));
        
        $this->assertnull(yield $stream->read());
    }
    
    public function testDetectsInvalidChunkHeader()
    {
        $stream = new ChunkDecodedStream(new ReadableMemoryStream('foo'));
    
        $this->expectException(StreamException::class);
        
        yield $stream->read();
    }
    
    public function testDetectsChunkLengthOverflow()
    {
        $stream = new ChunkDecodedStream(new ReadableMemoryStream('10000000'));
    
        $this->expectException(StreamException::class);
    
        yield $stream->read();
    }
    
    public function testCanReadSingleChunk()
    {
        $stream = new ChunkDecodedStream(new ReadableMemoryStream("3\r\nFOO\r\n0\r\n\r\n"));
        
        $this->assertEquals('FOO', yield $stream->read());
        $this->assertnull(yield $stream->read());
    }
    
    public function testDetectsMissingBreakAfterChunk()
    {
        $stream = new ChunkDecodedStream(new ReadableMemoryStream("3\r\nFOO\n0\r\n\r\n"));
        
        $this->assertEquals('FOO', yield $stream->read());
        
        $this->expectException(StreamException::class);
        
        yield $stream->read();
    }
    
    public function testDetectsInvalidBreakAfterChunk()
    {
        $stream = new ChunkDecodedStream(new ReadableMemoryStream("3\r\nFOOBAR"));
    
        $this->assertEquals('FOO', yield $stream->read());
    
        $this->expectException(StreamException::class);
    
        yield $stream->read();
    }
    
    public function provideChunkedContents()
    {
        yield ['', 8192];
        yield ['A', 1];
        yield [random_bytes(10), 1];
        yield [random_bytes(20), 5];
        yield [random_bytes(30), 8];
        yield [random_bytes(40), 16];
        yield [random_bytes(2000), 256];
        yield [random_bytes(10000), 8192];
        yield [random_bytes(100000), 8192];
    }

    /**
     * @dataProvider provideChunkedContents
     */
    public function testCanDecodeChunkedData(string $data, int $len)
    {
        $stream = new ChunkDecodedStream(new ReadableMemoryStream($this->chunkEncode($data, $len)));
        
        $this->assertEquals($data, yield new ReadContents($stream));
        $this->assertnull(yield $stream->read());
    }
}
