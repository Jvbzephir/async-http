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

use function KoolKode\Async\tempStream;

class ChunkDecodedInputStreamTest extends \PHPUnit_Framework_TestCase
{
    use AsyncTrait;
    
    protected function chunkEncode(string $data, int $len): string
    {
        $chunked = '';
        
        foreach (str_split($data, $len) as $chunk) {
            $chunked .= sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk);
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
            $in = yield from ChunkDecodedInputStream::open(yield tempStream($this->chunkEncode($data, $len)));
            
            if ($data !== '') {
                $this->assertFalse($in->eof());
            }
            
            $decoded = yield from $this->readContents($in);
            $this->assertTrue($in->eof());
            $this->assertEquals($data, $decoded);
            $this->assertEquals('0 bytes buffered', $in->__debugInfo()['buffer']);
        });
        
        $executor->run();
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testDetectsInvalidFirstChunk()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            yield from ChunkDecodedInputStream::open(yield tempStream("FOOBAR\r\nBAZ!\r\n"));
        });
        
        $executor->run();
    }
    
    /**
     * @expectedException \RuntimeException
     */
    public function testDetectsFirstChunkTooBig()
    {
        $executor = $this->createExecutor();
    
        $executor->runCallback(function () {
            yield from ChunkDecodedInputStream::open(yield tempStream("FFFFFFFF\r\n"));
        });
    
        $executor->run();
    }
    
    /**
     * @expectedException \KoolKode\Async\Stream\SocketClosedException
     */
    public function testDetectsReadFromClosedStream()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = yield from ChunkDecodedInputStream::open(yield tempStream($this->chunkEncode('Hello World this is some data...', 10)));
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
    public function testDetectsReadFromStreamAfterEof()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = yield from ChunkDecodedInputStream::open(yield tempStream($this->chunkEncode('H', 10)));
            $this->assertFalse($in->eof());
            
            $this->assertEquals('H', yield from $in->read());
            $this->assertTrue($in->eof());
            
            yield from $in->read();
        });
        
        $executor->run();
    }
    
    /**
     * @expectedException \RuntimeException
     */
    public function testDetectsMalformedChunkHeaderWithinStream()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $in = yield from ChunkDecodedInputStream::open(yield tempStream("3;foo\r\nFOO\r\nBAR\r\n"));
            $this->assertFalse($in->eof());
            
            yield from $this->readContents($in);
        });
        
        $executor->run();
    }
    
    /**
     * @expectedException \RuntimeException
     */
    public function testDetectsChunkWithinStreamTooBig()
    {
        $executor = $this->createExecutor();
    
        $executor->runCallback(function () {
            $in = yield from ChunkDecodedInputStream::open(yield tempStream("3;foo=\"bar\"\r\nFOO\r\nFFFFFFFF\r\n"));
            $this->assertFalse($in->eof());
    
            yield from $this->readContents($in);
        });
    
        $executor->run();
    }
}