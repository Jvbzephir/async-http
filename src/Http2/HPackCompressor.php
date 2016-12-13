<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Http2;

/**
 * Implementation of a canonical Huffman encoder / decoder as used by HPACK.
 * 
 * @author Martin Schröder
 */
class HPackCompressor
{
    /**
     * Compress input using Huffman encoding.
     * 
     * Output will be padded with bits set to 1.
     * 
     * @param string $input
     * @return string
     */
    public function compress(string $input): string
    {
        static $table;
        
        if ($table === null) {
            $table = $this->generateCompressionTable();
        }
        
        $size = \strlen($input);
        $encoded = \str_repeat("\x00", $size * 4);
        
        $byte = 0;
        $offset = 0;
        $buffer = 0;
        
        for ($i = 0; $i < $size; $i++) {
            foreach ($table[$input[$i]] as list ($code, $len)) {
                $buffer |= $code >> $offset;
                $offset += $len;
                
                if ($offset > 7) {
                    $offset -= 8;
                    $encoded[$byte++] = \chr($buffer >> 8 & 0xFF);
                    $buffer <<= 8;
                }
            }
        }
        
        if ($offset > 0) {
            $encoded[$byte++] = \chr(($buffer >> 8 & 0xFF) | 0xFF >> $offset);
        }
        
        return \substr($encoded, 0, $byte);
    }
    
    /**
     * Generate lookup table used during compression.
     * 
     * Huffman codes are split into 8 bit chunks to facilitate easy concatenation of output bytes.
     * 
     * @return array
     */
    protected function generateCompressionTable(): array
    {
        static $pad = 32;
        
        $table = [];
        
        foreach (self::HUFFMAN_CODE as $i => $code) {
            $len = self::HUFFMAN_CODE_LENGTHS[$i];
            $code = $code << ($pad - $len);
            
            for ($j = 0; $j < $len; $j += 8) {
                $table[\chr($i)][] = [
                    ($code >> ($pad - 8 - $j) & 0xFF) << 8,
                    \min(8, $len - $j)
                ];
            }
        }
        
        return $table;
    }

    /**
     * Decompress the given Huffman-encoded input.
     * 
     * @param string $input
     * @return string
     */
    public function decompress(string $input): string
    {
        static $table;
        static $lens;
        
        if ($table === null) {
            list ($table, $lens) = $this->generateDecompressionTables();
        }
        
        $size = \strlen($input);
        $decoded = \str_repeat("\x00", (int) \ceil($size / 8 * 5 + 1));
        
        $entry = null;
        
        $len = 0;
        $byte = 0;
        $buffer = 0;
        $available = 0;
        
        for ($i = 0; $i < $size; $i++) {
            $buffer |= \ord($input[$i]) << (8 - $available);
            $available += 8;
            
            do {
                $entry = ($entry ?? $table)[$buffer >> 8 & 0xFF] ?? null;
                
                if (\is_array($entry)) {
                    $len = 8;
                } else {
                    $len = $lens[$entry];
                    $decoded[$byte++] = $entry;
                    
                    $entry = null;
                }
                
                $available -= $len;
                $buffer <<= $len;
            } while ($available > 7);
        }
        
        if ($available > 0) {
            return \substr($decoded, 0, $byte) . $this->decompressTrailer($table, $lens, $buffer, $available, $entry);
        }
        
        return \substr($decoded, 0, $byte);
    }
    
    /**
     * Decompress the last chunk of compressed data and strip padding as needed.
     * 
     * @return string Decoded remaining data.
     */
    protected function decompressTrailer(array $table, array $lens, int $buffer, int $available, array $entry = null): string
    {
        $decoded = '';
        
        do {
            $entry = ($entry ?? $table)[$buffer >> 8 & 0xFF] ?? null;
            
            if ($entry === null || \is_array($entry) || ($len = $lens[$entry]) > $available) {
                break;
            }
            
            $decoded .= $entry;
            $entry = null;
            
            $available -= $len;
            $buffer <<= $len;
        } while ($available > 0);
        
        if ($available > 0) {
            switch (($buffer >> 8 & 0xFF) >> (8 - $available)) {
                case 0b1:
                case 0b11:
                case 0b111:
                case 0b1111:
                case 0b11111:
                case 0b111111:
                case 0b1111111:
                    // Valid padding detected. :)
                    break;
                default:
                    $trailer = ($buffer >> 8 & 0xFF) >> (8 - $available);
                    
                    throw new \RuntimeException(\sprintf('Invalid HPACK padding in compressed string detected: %08b', $trailer));
            }
        }
        
        return $decoded;
    }

    /**
     * Generate the lookup table being used to decode input bytes.
     * 
     * @return array
     */
    protected function generateDecompressionTables(): array
    {
        $table = [];
        $lens = [];
        
        foreach (self::HUFFMAN_CODE as $i => $code) {
            $len = self::HUFFMAN_CODE_LENGTHS[$i];
            
            $char = ($i > 255) ? '' : \chr($i);
            $lens[$char] = ($len % 8) ?: 8;
            
            if ($len > 8) {
                $local = ($code >> ($len - 8)) & 0xFF;
            } else {
                $local = ($code << (8 - $len)) & 0xFF;
            }
            
            if ($len > 8) {
                if (!isset($table[$local])) {
                    $table[$local] = [];
                }
                
                $this->populateDecompressionTable($table[$local], $i, $code, $len, 2);
            } else {
                $table[$local] = $char;
                
                for ($max = (1 << 8 - $len), $j = 0; $j < $max; $j++) {
                    $table[$local | $j] = $char;
                }
            }
        }
        
        return [
            $table,
            $lens
        ];
    }

    /**
     * Populate a branch of the lookup table with Huffman-encoded bytes.
     */
    protected function populateDecompressionTable(array & $table, int $i, int $code, int $len, int $level)
    {
        $char = ($i > 255) ? '' : \chr($i);
        
        if ($len > 8 * $level) {
            $local = ($code >> ($len - 8 * $level)) & 0xFF;
        } else {
            $local = ($code << (8 * $level - $len)) & 0xFF;
        }
        
        if ($len > (8 * $level)) {
            if (!isset($table[$local])) {
                $table[$local] = [];
            }
            
            $this->populateDecompressionTable($table[$local], $i, $code, $len, $level + 1);
        } else {
            $table[$local] = $char;
            
            for ($max = (1 << 8 * $level - $len), $j = 0; $j < $max; $j++) {
                $table[$local | $j] = $char;
            }
        }
    }
    
    /**
     * Huffman codes from HPACK specification.
     *
     * @var array
     */
    const HUFFMAN_CODE = [
        0x1FF8, 0x7FFFD8,  0xFFFFFE2, 0xFFFFFE3, 0xFFFFFE4, 0xFFFFFE5, 0xFFFFFE6, 0xFFFFFE7,
        0xFFFFFE8, 0xFFFFEA, 0x3FFFFFFC, 0xFFFFFE9, 0xFFFFFEA, 0x3FFFFFFD, 0xFFFFFEB, 0xFFFFFEC,
        0xFFFFFED, 0xFFFFFEE, 0xFFFFFEF, 0xFFFFFF0, 0xFFFFFF1, 0xFFFFFF2, 0x3FFFFFFE, 0xFFFFFF3,
        0xFFFFFF4, 0xFFFFFF5, 0xFFFFFF6, 0xFFFFFF7, 0xFFFFFF8, 0xFFFFFF9, 0xFFFFFFA, 0xFFFFFFB,
        0x14, 0x3F8, 0x3F9, 0xFFA, 0x1FF9, 0x15, 0xF8, 0x7FA,
        0x3FA, 0x3FB, 0xF9, 0x7FB, 0xFA, 0x16, 0x17, 0x18,
        0x0, 0x1, 0x2, 0x19, 0x1A, 0x1B, 0x1C, 0x1D,
        0x1E, 0x1F, 0x5C, 0xFB, 0x7FFC, 0x20, 0xFFB, 0x3FC,
        0x1FFA, 0x21, 0x5D, 0x5E, 0x5F, 0x60, 0x61, 0x62,
        0x63, 0x64, 0x65, 0x66, 0x67, 0x68, 0x69, 0x6A,
        0x6B, 0x6C, 0x6D, 0x6E, 0x6F, 0x70, 0x71, 0x72,
        0xFC, 0x73, 0xFD, 0x1FFB, 0x7FFF0, 0x1FFC, 0x3FFC, 0x22,
        0x7FFD, 0x3, 0x23, 0x4, 0x24, 0x5, 0x25, 0x26,
        0x27, 0x6, 0x74, 0x75, 0x28, 0x29, 0x2A, 0x7,
        0x2B, 0x76, 0x2C, 0x8, 0x9, 0x2D, 0x77, 0x78,
        0x79, 0x7A, 0x7B, 0x7FFE, 0x7FC, 0x3FFD, 0x1FFD, 0xFFFFFFC,
        0xFFFE6, 0x3FFFD2, 0xFFFE7, 0xFFFE8, 0x3FFFD3, 0x3FFFD4, 0x3FFFD5, 0x7FFFD9,
        0x3FFFD6, 0x7FFFDA, 0x7FFFDB, 0x7FFFDC, 0x7FFFDD, 0x7FFFDE, 0xFFFFEB, 0x7FFFDF,
        0xFFFFEC, 0xFFFFED, 0x3FFFD7, 0x7FFFE0, 0xFFFFEE, 0x7FFFE1, 0x7FFFE2, 0x7FFFE3,
        0x7FFFE4, 0x1FFFDC, 0x3FFFD8, 0x7FFFE5, 0x3FFFD9, 0x7FFFE6, 0x7FFFE7, 0xFFFFEF,
        0x3FFFDA, 0x1FFFDD, 0xFFFE9, 0x3FFFDB, 0x3FFFDC, 0x7FFFE8, 0x7FFFE9, 0x1FFFDE,
        0x7FFFEA, 0x3FFFDD, 0x3FFFDE, 0xFFFFF0, 0x1FFFDF, 0x3FFFDF, 0x7FFFEB, 0x7FFFEC,
        0x1FFFE0, 0x1FFFE1, 0x3FFFE0, 0x1FFFE2, 0x7FFFED, 0x3FFFE1, 0x7FFFEE, 0x7FFFEF,
        0xFFFEA, 0x3FFFE2, 0x3FFFE3, 0x3FFFE4, 0x7FFFF0, 0x3FFFE5, 0x3FFFE6, 0x7FFFF1,
        0x3FFFFE0, 0x3FFFFE1, 0xFFFEB, 0x7FFF1, 0x3FFFE7, 0x7FFFF2, 0x3FFFE8, 0x1FFFFEC,
        0x3FFFFE2, 0x3FFFFE3, 0x3FFFFE4, 0x7FFFFDE, 0x7FFFFDF, 0x3FFFFE5, 0xFFFFF1, 0x1FFFFED,
        0x7FFF2, 0x1FFFE3, 0x3FFFFE6, 0x7FFFFE0, 0x7FFFFE1, 0x3FFFFE7, 0x7FFFFE2, 0xFFFFF2,
        0x1FFFE4, 0x1FFFE5, 0x3FFFFE8, 0x3FFFFE9, 0xFFFFFFD, 0x7FFFFE3, 0x7FFFFE4, 0x7FFFFE5,
        0xFFFEC, 0xFFFFF3, 0xFFFED, 0x1FFFE6, 0x3FFFE9, 0x1FFFE7, 0x1FFFE8, 0x7FFFF3,
        0x3FFFEA, 0x3FFFEB, 0x1FFFFEE, 0x1FFFFEF, 0xFFFFF4, 0xFFFFF5, 0x3FFFFEA, 0x7FFFF4,
        0x3FFFFEB, 0x7FFFFE6, 0x3FFFFEC, 0x3FFFFED, 0x7FFFFE7, 0x7FFFFE8, 0x7FFFFE9, 0x7FFFFEA,
        0x7FFFFEB, 0xFFFFFFE, 0x7FFFFEC, 0x7FFFFED, 0x7FFFFEE, 0x7FFFFEF, 0x7FFFFF0, 0x3FFFFEE
    ];
    
    /**
     * Huffman codes lengths according to HPACK specification.
     *
     * @var array
     */
    const HUFFMAN_CODE_LENGTHS = [
        13, 23, 28, 28, 28, 28, 28, 28,
        28, 24, 30, 28, 28, 30, 28, 28,
        28, 28, 28, 28, 28, 28, 30, 28,
        28, 28, 28, 28, 28, 28, 28, 28,
        6, 10, 10, 12, 13, 6, 8, 11,
        10, 10, 8, 11, 8, 6, 6, 6,
        5, 5, 5, 6, 6, 6, 6, 6,
        6, 6, 7, 8, 15, 6, 12, 10,
        13, 6, 7, 7, 7, 7, 7, 7,
        7, 7, 7, 7, 7, 7, 7, 7,
        7, 7, 7, 7, 7, 7, 7, 7,
        8, 7, 8, 13, 19, 13, 14, 6,
        15, 5, 6, 5, 6, 5, 6, 6,
        6, 5, 7, 7, 6, 6, 6, 5,
        6, 7, 6, 5, 5, 6, 7, 7,
        7, 7, 7, 15, 11, 14, 13, 28,
        20, 22, 20, 20, 22, 22, 22, 23,
        22, 23, 23, 23, 23, 23, 24, 23,
        24, 24, 22, 23, 24, 23, 23, 23,
        23, 21, 22, 23, 22, 23, 23, 24,
        22, 21, 20, 22, 22, 23, 23, 21,
        23, 22, 22, 24, 21, 22, 23, 23,
        21, 21, 22, 21, 23, 22, 23, 23,
        20, 22, 22, 22, 23, 22, 22, 23,
        26, 26, 20, 19, 22, 23, 22, 25,
        26, 26, 26, 27, 27, 26, 24, 25,
        19, 21, 26, 27, 27, 26, 27, 24,
        21, 21, 26, 26, 28, 27, 27, 27,
        20, 24, 20, 21, 22, 21, 21, 23,
        22, 22, 25, 25, 24, 24, 26, 23,
        26, 27, 26, 26, 27, 27, 27, 27,
        27, 28, 27, 27, 27, 27, 27, 26
    ];
}
