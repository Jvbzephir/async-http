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
 * @covers \KoolKode\Async\Http\Http2\HPackDecoder
 *
 */
class HPackDecoderTest extends \PHPUnit_Framework_TestCase
{    
    public function testCanDecodeUsingTable()
    {
        static $lens;
        
        if ($lens === null) {
            for ($i = 0; $i < 256; $i++) {
                $lens[\chr($i)] = HPack::HUFFMAN_CODE_LENGTHS[$i];
            }
        }
        
        $input = '1100011 00101 101000 101000 00111 010100 1110010 00111 101100 101000 100100 1111111000';
        $str = '';
        
        foreach (str_split(preg_replace("'\s+'", '', $input), 8) as $chunk) {
            $str .= chr(bindec(str_pad($chunk, 8, '1', STR_PAD_RIGHT)));
        }
        
        $table = require dirname(__DIR__, 3) . '/generated/hpack.decoder.php';
        
        $reader = new HPackDecoder($str);
        $consumed = 0;
        
        $entry = null;
        $level = 0;
        $decoded = '';
        
        while (true) {
            $byte = $reader->readNextByte($consumed);
            
            if ($byte === null) {
                if (!$reader->isPaddingByte($reader->getByte())) {
                    throw new \RuntimeException('Invalid padding in HPACK compressed string detected');
                }
                
                break;
            }
            
            if ($entry === null) {
                $entry = $table[$byte] ?? null;
            } else {
                $entry = $entry[$byte] ?? null;
            }
            
            if ($entry === null) {
                throw new \RuntimeException(\sprintf('Failed to decode byte: %08b (missing decoder table entry)', $byte));
            }
            
            if (\is_array($entry)) {
                $consumed = 8;
                $level++;
            } else {
                $consumed = $lens[$entry] % 8;
                $decoded .= $entry;
                
                $entry = null;
                $level = 0;
            }
        }
        
        $this->assertEquals('Hello World!', $decoded);
    }
    
    public function testEncoder2()
    {
        $symbols = [
            'A' => 0b100,
            'B' => 0b110,
            'C' => 0b1110,
            'D' => 0b1011100011
        ];
        
        $lens = [
            'A' => 3,
            'B' => 3,
            'C' => 4,
            'D' => 10
        ];
        
        $table = [];
        $pad = 32;
        
        foreach ($symbols as $i => $code) {
            $len = $lens[$i];
            $code = $code << ($pad - $len);
            
            for ($j = 0; $j < $len; $j += 8) {
                $table[$i][] = [
                    ($code >> ($pad - 8 - $j) & 0xFF) << 8,
                    \min(8, $len - $j)
                ];
            }
        }
        
        $input = 'ACDC';
        $encoded = '';
        
        $offset = 0;
        $buffer = 0;
        
        for ($size = \strlen($input), $i = 0; $i < $size; $i++) {
            foreach ($table[$input[$i]] as list ($code, $len)) {
                $buffer |= $code >> $offset;
                $offset += $len;
                
                if ($offset > 7) {
                    $offset -= 8;
                    $encoded .= \chr($buffer >> 8 & 0xFF);
                    $buffer <<= 8;
                }
            }
        }
        
        if ($offset > 0) {
            $encoded .= \chr(($buffer >> 8 & 0xFF) | (1 << (8 - $offset)) - 1);
        }
        
        $bytes = array_map('ord', str_split($encoded, 1));
        
        $this->assertEquals([
            0b10011101,
            0b01110001,
            0b11110111
        ], $bytes);
        
//         echo "\n\n", implode(' ', array_map(function ($char) {
//             return sprintf('%08b', ord($char));
//         }, str_split($encoded, 1))), "\n\n";
    }
    
    public function testEncoder()
    {
        $symbols = [
            'A' => 0b100,
            'B' => 0b110,
            'C' => 0b1110,
            'D' => 0b1011100011
        ];
        
        $lens = [
            'A' => 3,
            'B' => 3,
            'C' => 4,
            'D' => 10
        ];
        
        $input = 'ACDC';
        $encoded = '';
        
        $byte = 0;
        $remaining = 8;
        
        for ($size = \strlen($input), $i = 0; $i < $size; $i++) {
            $code = $symbols[$input[$i]];
            $slen = $lens[$input[$i]];
            
            $code = $code << (32 - $slen);
            $shift = 24;
            
            while ($slen) {
                $len = \min($slen, 8);
                $slen -= $len;
                
                while (true) {
                    $byte |= (($code >> $shift) & 0xFF) >> (8 - $remaining);
                    
                    
                    if ($len <= $remaining) {
                        $remaining -= $len;
                        $shift -= $len;
                        $len = 0;
                        
                        if ($remaining) {
                            break;
                        }
                    } else {
                        $shift -= $remaining;
                        $len -= $remaining;
                    }
                    
                    $encoded .= \chr($byte);
                    $byte = 0;
                    $remaining = 8;
                }
            }
        }
        
        if ($remaining < 8) {
            $encoded .= \chr($byte | (1 << $remaining) - 1);
        }
        
        $bytes = array_map('ord', str_split($encoded, 1));
        
        $this->assertEquals([
            0b10011101,
            0b01110001,
            0b11110111
        ], $bytes);
    }
}
