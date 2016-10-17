<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http2;

/**
 * @covers \KoolKode\Async\Http\Http2\HPackCompressor
 *
 */
class HPackCompressorTest extends \PHPUnit_Framework_TestCase
{
    public function providePlainData()
    {
        yield ['A'];
        yield ['Test 1'];
        yield ['Hello World!'];
        
        $str = '';
        
        for ($i = 0; $i < 256; $i++) {
            $str .= chr($i);
        }
        
        yield [$str];
        
        for ($j = 0; $j < 20; $j++) {
            $str = '';
            
            for ($i = 0; $i < 16; $i++) {
                $str .= chr(random_int(0, 255));
            }
            
            yield [$str];
        }
    }
    
    /**
     * @dataProvider providePlainData
     */
    public function testCompression(string $plain)
    {
        $encoded = (new HPackCompressor())->compress($plain);
        
        $this->assertEquals($this->compress($plain), $encoded);
    }
    
    /**
     * @dataProvider providePlainData
     */
    public function testDecompression(string $plain)
    {
        $decoded = (new HPackCompressor())->decompress($this->compress($plain));
        
        $this->assertEquals($plain, $decoded);
    }
    
    public function providePadding()
    {
        yield 'no padding' => [';', '11111011'];
        yield '1 byte' => [':', '1011100'];
        yield '2 byte' => ['A', '100001'];
        yield '3 byte' => ['2', '00010'];
        yield '4 byte' => [':c', '1011100 00100'];
        yield '5 byte' => ['Ai', '100001 00110'];
        yield '6 byte' => ['cc', '00100 00100'];
        yield '7 byte' => ['>2', '111111111011 00010'];
    }
    
    /**
     * @dataProvider providePadding
     */
    public function testPaddingDetection(string $plain, string $compressed)
    {
        $this->assertEquals($plain, (new HPackCompressor())->decompress($this->toBinary($compressed)));
    }
    
    public function testDetectsInvalidPadding()
    {
        $compressor = new HPackCompressor();
        
        $this->expectException(\RuntimeException::class);
        
        $compressor->decompress($this->toBinary('10000110'));
    }

    protected function compress(string $input): string
    {
        $compressed = '';
        
        foreach (str_split($input, 1) as $char) {
            $len = HPackCompressor::HUFFMAN_CODE_LENGTHS[ord($char)];
            $compressed .= sprintf("%0{$len}b ", HPackCompressor::HUFFMAN_CODE[ord($char)]);
        }
        
        return $this->toBinary($compressed);
    }

    protected function toBinary(string $input): string
    {
        return implode('', array_map(function (string $chunk) {
            return chr(bindec(str_pad($chunk, 8, '1', STR_PAD_RIGHT)));
        }, str_split(preg_replace("'\s+'", '', $input), 8)));
    }
}
