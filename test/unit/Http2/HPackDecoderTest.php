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
        
        echo "\n\n";
        
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
                $entry = $entry[2][$byte] ?? null;
            }
            
            if ($entry === null) {
                throw new \RuntimeException(\sprintf('Failed to decode byte: %08b (missing decoder table entry)', $byte));
            }
            
            if (isset($entry[2])) {
                $consumed = 8;
                $level++;
            } else {
                $consumed = $entry[1] % 8;
                $decoded .= $entry[0];
                
                $entry = null;
                $level = 0;
            }
        }
        
        $this->assertEquals('Hello World!', $decoded);
    }
}
