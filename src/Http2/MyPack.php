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

class MyPack
{
    const SIZE_LIMIT = 4096;
    
    protected $size = 0;
    
    protected $maxSize = 4096;
    
    protected $table = [];
    
    public function getDynamicTableSize(): int
    {
        return count($this->table);
    }
    
    public function encode(array $headers): string
    {
        $result = '';
        
        foreach ($headers as list ($k, $v)) {
            $index = self::STATIC_TABLE_LOOKUP[$k . ':' . $v] ?? NULL;
            
            if ($index !== NULL) {
                if ($index < 0x7F) {
                    $result .= chr($index | 0x80);
                } else {
                    $result .= ($this->encodeInt($index) | "\x80");
                }
                
                continue;
            }
            
            $index = self::STATIC_TABLE_LOOKUP[$k] ?? NULL;
            
            if ($index !== NULL) {
                if ($index < 0x7F) {
                    $result .= chr($index);
                } else {
                    $result .= $this->encodeInt($index);
                }
            } elseif (strlen($k) < 0x7F) {
                $result .= "\x00" . chr(strlen($k)) . $k;
            } else {
                $result .= "\x00" . $this->encodeInt(strlen($k)) . $k;
            }
            
            if (strlen($v) < 0x7F) {
                $result .= chr(strlen($v)) . $v;
            } else {
                $result .= $this->encodeInt(strlen($v)) . $v;
            }
        }
        
        return $result;
    }
    
    public function decode(string $encoded): array
    {
        $headers = [];
        $encodedLength = strlen($encoded);
        $offset = 0;
        
        while ($offset < $encodedLength) {
            $index = ord($encoded[$offset++]);
            
            // Indexed Header Field Representation
            if ($index & 0x80) {
                if ($index <= self::STATIC_TABLE_SIZE + 0x80) {
                    if ($index === 0x80) {
                        throw new \RuntimeException(sprintf('Cannot access index %X in static table', $index));
                    }
                    
                    $headers[] = self::STATIC_TABLE[$index - 0x80];
                } else {
                    if ($index == 0xFF) {
                        $index = self::decodeInt($encoded, $offset) + 0xFF;
                    }
                    
                    $index -= 0x81 + self::STATIC_TABLE_SIZE;
                    
                    if (!isset($this->table[$index])) {
                        throw new \RuntimeException(sprintf('Missing index %X in dynamic table', $index));
                    }
                    
                    $headers[] = $this->table[$index];
                }
                
                continue;
            }
            
            // Literal Header Field Representation
            if (($index & 0x60) != 0x20) {
                $dynamic = ($index & 0x40) ? true : false;
                
                if ($index & ($dynamic ? 0x3F : 0x0F)) {
                    if ($dynamic) {
                        if ($index == 0x7F) {
                            $index = $this->decodeInt($encoded, $offset) + 0x3F;
                        } else {
                            $index &= 0x3F;
                        }
                    } else {
                        $index &= 0x0F;
                        
                        if ($index == 0x0F) {
                            $index = $this->decodeInt($encoded, $offset) + 0x0F;
                        }
                    }
                    
                    if ($index <= self::STATIC_TABLE_SIZE) {
                        $name = self::STATIC_TABLE[$index][0];
                    } else {
                        $name = $this->table[$index - self::STATIC_TABLE_SIZE];
                    }
                } else {
                    $name = $this->decodeString($encoded, $encodedLength, $offset);
                }
                
                if ($offset === $encodedLength) {
                    throw new \RuntimeException(sprintf('Failed to decode value of header "%s"', $name));
                }
                
                $headers[] = $header = [
                    $name,
                    $this->decodeString($encoded, $encodedLength, $offset)
                ];
                
                if ($dynamic) {
                    array_unshift($this->table, $header);
                    $this->size += 32 + strlen($header[0]) + strlen($header[1]);
                    
                    if ($this->maxSize < $this->size) {
                        var_dump('DYNAMIC TABLE RESIZE');
                    }
                }
                
                continue;
            }
            
            // Dynamic Table Size Update
            if ($index == 0x3F) {
                $index = $this->decodeInt($encoded, $offset) + 0x40;
            }
            
            if ($index > self::SIZE_LIMIT) {
                throw new \RuntimeException(sprintf('Attempting to resize dynamic table to %u, limit is %u', $index, self::SIZE_LIMIT));
            }
            
            var_dump('TABLE RESIZE');
        }
        
        return $headers;
    }
    
    protected function encodeInt(int $int)
    {
        $result = '';
        $i = 0;
        
        while (($int >> $i) > 0x80) {
            $result .= chr(0x80 | (($int >> $i) & 0x7F));
            $i += 7;
        }
        
        return $result . chr($int >> $i);
    }
    
    protected function decodeInt(string $encoded, int & $offset)
    {
        $byte = ord($encoded[$offset++]);
        $int = $byte & 0x7F;
        $i = 0;
        
        while ($byte & 0x80) {
            if (!isset($encoded[$offset])) {
                return -0x80;
            }
            
            $byte = ord($encoded[$offset++]);
            $int += ($byte & 0x7F) << (++$i * 7);
        }
        
        return $int;
    }
    
    protected function decodeString(string $encoded, int $encodedLength, int & $offset)
    {
        $len = ord($encoded[$offset++]);
        $huffman = ($len & 0x80) ? true : false;
        $len &= 0x7F;
        
        if ($len == 0x7F) {
            $len = $this->decodeInt($encoded, $offset) + 0x7F;
        }
        
        if (($encodedLength - $offset) < $len || $len <= 0) {
            throw new \RuntimeException('Failed to read encoded string');
        }
        
        try {
            if ($huffman) {
                return $this->decodeHuffmanString(substr($encoded, $offset, $len));
            }
            
            return substr($encoded, $offset, $len);
        } finally {
            $offset += $len;
        }
    }
    
    /**
     * Contains encoded symbols (characters), keys are the Huffman codes.
     * 
     * @var array
     */
    private static $symbols = [];

    /**
     * Contains the number of codes by code length (bit count).
     * 
     * @var array
     */
    private static $codeLengths = [];

    /**
     * Contains the first (and according to canonical Huffman encoding lowest) code for each code length.
     * 
     * @var array
     */
    private static $startCodes = [];

    /**
     * Holds a sequence of increments that determine the number of bits to be read to reach the next code length.
     * 
     * @var array
     */
    private static $steps = [];

    /**
     * Total number of increments (steps) needed in order to read the longest Huffman code.
     * 
     * @var int
     */
    private static $stepCount = 0;
    
    /**
     * Prepare Huffman decoder by sorting codes and precomputing some helper arrays.
     */
    protected function initializeHuffmanCode()
    {
        $sorter = new \SplPriorityQueue();
        
        // Sort codes by length and create symbol table.
        foreach (self::HUFFMAN_CODE as $i => $code) {
            self::$symbols[$code] = ($i > 255) ? NULL : chr($i);
            
            $sorter->insert([
                $code,
                self::HUFFMAN_CODE_LENGTHS[$i]
            ], -1 * self::HUFFMAN_CODE_LENGTHS[$i]);
        }
        
        // Compute code length distribution and keep track of first code for each length.
        while (!$sorter->isEmpty()) {
            list ($code, $len) = $sorter->extract();
            
            if (isset(self::$codeLengths[$len])) {
                self::$codeLengths[$len]++;
            } else {
                self::$codeLengths[$len] = 1;
            }
            
            if (!isset(self::$startCodes[$len])) {
                self::$startCodes[$len] = $code;
            }
        }
        
        // Compute number of additional bits to be read when switching to next code length.
        $lens = array_keys(self::$codeLengths);
        self::$stepCount = count($lens);
        
        foreach ($lens as $i => $step) {
            self::$steps[] = $step - ($i ? $lens[$i - 1] : 0);
        }
    }
    
    /**
     * Decode a canonical Huffman-encoded string.
     * 
     * @param string $encoded Encoded string.
     * @return string Decoded string.
     * 
     * @throws \RuntimeException When the string contains invalid padding or a code could not found.
     */
    protected function decodeHuffmanString(string $encoded): string
    {
        if (empty(self::$symbols)) {
            $this->initializeHuffmanCode();
        }
        
        $decoded = '';
        $buffer = '';
        
        $byteOffset = 0;
        $bitOffset = 7;
        
        while (true) {
            $code = 0;
            $codeLen = 0;
            
            for ($step = 0; $step < self::$stepCount; $step++) {
                for ($n = 0; $n < self::$steps[$step]; $n++) {
                    if ($buffer === '') {
                        if ($byteOffset == strlen($encoded)) {
                            if ($this->isHuffmanPaddingCode($code)) {
                                return $decoded;
                            }
                            
                            throw new \RuntimeException('Cannot read beyond end of Huffman-encoded string');
                        }
                        
                        $buffer = ord($encoded[$byteOffset]);
                    }
                    
                    $code = ($code << 1) | (($buffer >> $bitOffset--) & 1);
                    
                    if ($bitOffset == -1) {
                        $byteOffset++;
                        $bitOffset = 7;
                        $buffer = '';
                    }
                }
                
                $codeLen += self::$steps[$step];
                
                if ($code > self::$startCodes[$codeLen] && ($code - self::$startCodes[$codeLen]) < self::$codeLengths[$codeLen]) {
                    $decoded .= self::$symbols[$code];
                    
                    continue 2;
                }
            }
            
            if ($this->isHuffmanPaddingCode($code)) {
                return $decoded;
            }
            
            break;
        }
        
        throw new \RuntimeException('Invalid Huffman code detected');
    }
    
    /**
     * Check if the given code is allowed as final (padding) byte of a Huffman-encoded HPACK string.
     * 
     * @param int $code
     * @return bool
     */
    protected function isHuffmanPaddingCode(int $code): bool
    {
        switch ($code) {
            case 0b1:
            case 0b11:
            case 0b111:
            case 0b1111:
            case 0b11111:
            case 0b111111:
            case 0b1111111:
                return true;
        }
        
        return false;
    }
    
    const STATIC_TABLE_SIZE = 61;
    
    const STATIC_TABLE = [
        1 => [
            ':authority',
            ''
        ],
        [
            ':method',
            'GET'
        ],
        [
            ':method',
            'POST'
        ],
        [
            ':path',
            '/'
        ],
        [
            ':path',
            '/index.html'
        ],
        [
            ':scheme',
            'http'
        ],
        [
            ':scheme',
            'https'
        ],
        [
            ':status',
            '200'
        ],
        [
            ':status',
            '204'
        ],
        [
            ':status',
            '206'
        ],
        [
            ':status',
            '304'
        ],
        [
            ':status',
            '400'
        ],
        [
            ':status',
            '404'
        ],
        [
            ':status',
            '500'
        ],
        [
            'accept-charset',
            ''
        ],
        [
            'accept-encoding',
            'gzip, deflate'
        ],
        [
            'accept-language',
            ''
        ],
        [
            'accept-ranges',
            ''
        ],
        [
            'accept',
            ''
        ],
        [
            'access-control-allow-origin',
            ''
        ],
        [
            'age',
            ''
        ],
        [
            'allow',
            ''
        ],
        [
            'authorization',
            ''
        ],
        [
            'cache-control',
            ''
        ],
        [
            'content-disposition',
            ''
        ],
        [
            'content-encoding',
            ''
        ],
        [
            'content-language',
            ''
        ],
        [
            'content-length',
            ''
        ],
        [
            'content-location',
            ''
        ],
        [
            'content-range',
            ''
        ],
        [
            'content-type',
            ''
        ],
        [
            'cookie',
            ''
        ],
        [
            'date',
            ''
        ],
        [
            'etag',
            ''
        ],
        [
            'expect',
            ''
        ],
        [
            'expires',
            ''
        ],
        [
            'from',
            ''
        ],
        [
            'host',
            ''
        ],
        [
            'if-match',
            ''
        ],
        [
            'if-modified-since',
            ''
        ],
        [
            'if-none-match',
            ''
        ],
        [
            'if-range',
            ''
        ],
        [
            'if-unmodified-since',
            ''
        ],
        [
            'last-modified',
            ''
        ],
        [
            'link',
            ''
        ],
        [
            'location',
            ''
        ],
        [
            'max-forwards',
            ''
        ],
        [
            'proxy-authentication',
            ''
        ],
        [
            'proxy-authorization',
            ''
        ],
        [
            'range',
            ''
        ],
        [
            'referer',
            ''
        ],
        [
            'refresh',
            ''
        ],
        [
            'retry-after',
            ''
        ],
        [
            'server',
            ''
        ],
        [
            'set-cookie',
            ''
        ],
        [
            'strict-transport-security',
            ''
        ],
        [
            'transfer-encoding',
            ''
        ],
        [
            'user-agent',
            ''
        ],
        [
            'vary',
            ''
        ],
        [
            'via',
            ''
        ],
        [
            'www-authenticate',
            ''
        ]
    ];

    const STATIC_TABLE_LOOKUP = [
        ':authority' => 1,
        ':method' => 2,
        ':path' => 4,
        ':scheme' => 6,
        ':status' => 8,
        'accept-charset' => 15,
        'accept-encoding' => 16,
        'accept-language' => 17,
        'accept-ranges' => 18,
        'accept' => 19,
        'access-control-allow-origin' => 20,
        'age' => 21,
        'allow' => 22,
        'authorization' => 23,
        'cache-control' => 24,
        'content-disposition' => 25,
        'content-encoding' => 26,
        'content-language' => 27,
        'content-length' => 28,
        'content-location' => 29,
        'content-range' => 30,
        'content-type' => 31,
        'cookie' => 32,
        'date' => 33,
        'etag' => 34,
        'expect' => 35,
        'expires' => 36,
        'from' => 37,
        'host' => 38,
        'if-match' => 39,
        'if-modified-since' => 40,
        'if-none-match' => 41,
        'if-range' => 42,
        'if-unmodified-since' => 43,
        'last-modified' => 44,
        'link' => 45,
        'location' => 46,
        'max-forwards' => 47,
        'proxy-authentication' => 48,
        'proxy-authorization' => 49,
        'range' => 50,
        'referer' => 51,
        'retry-after' => 53,
        'server' => 54,
        'set-cookie' => 55,
        'strict-transport-security' => 56,
        'transfer-encoding' => 57,
        'user-agent' => 58,
        'vary' => 59,
        'via' => 60,
        'www-authenticate' => 61,
        ':authority:' => 1,
        ':method:GET' => 2,
        ':method:POST' => 3,
        ':path:/' => 4,
        ':path:/index.html' => 5,
        ':scheme:http' => 6,
        ':scheme:https' => 7,
        ':status:200' => 8,
        ':status:204' => 9,
        ':status:206' => 10,
        ':status:304' => 11,
        ':status:400' => 12,
        ':status:404' => 13,
        ':status:500' => 14,
        'accept-charset:' => 15,
        'accept-encoding:gzip, deflate' => 16,
        'accept-language:' => 17,
        'accept-ranges:' => 18,
        'accept:' => 19,
        'access-control-allow-origin:' => 20,
        'age:' => 21,
        'allow:' => 22,
        'authorization:' => 23,
        'cache-control:' => 24,
        'content-disposition:' => 25,
        'content-encoding:' => 26,
        'content-language:' => 27,
        'content-length:' => 28,
        'content-location:' => 29,
        'content-range:' => 30,
        'content-type:' => 31,
        'cookie:' => 32,
        'date:' => 33,
        'etag:' => 34,
        'expect:' => 35,
        'expires:' => 36,
        'from:' => 37,
        'host:' => 38,
        'if-match:' => 39,
        'if-modified-since:' => 40,
        'if-none-match:' => 41,
        'if-range:' => 42,
        'if-unmodified-since:' => 43,
        'last-modified:' => 44,
        'link:' => 45,
        'location:' => 46,
        'max-forwards:' => 47,
        'proxy-authentication:' => 48,
        'proxy-authorization:' => 49,
        'range:' => 50,
        'referer:' => 51,
        'refresh:' => 52,
        'retry-after:' => 53,
        'server:' => 54,
        'set-cookie:' => 55,
        'strict-transport-security:' => 56,
        'transfer-encoding:' => 57,
        'user-agent:' => 58,
        'vary:' => 59,
        'via:' => 60,
        'www-authenticate:' => 61
    ];

    const HUFFMAN_CODE = [
        0x1FF8,
        0x7FFFD8,
        0xFFFFFE2,
        0xFFFFFE3,
        0xFFFFFE4,
        0xFFFFFE5,
        0xFFFFFE6,
        0xFFFFFE7,
        0xFFFFFE8,
        0xFFFFEA,
        0x3FFFFFFC,
        0xFFFFFE9,
        0xFFFFFEA,
        0x3FFFFFFD,
        0xFFFFFEB,
        0xFFFFFEC,
        0xFFFFFED,
        0xFFFFFEE,
        0xFFFFFEF,
        0xFFFFFF0,
        0xFFFFFF1,
        0xFFFFFF2,
        0x3FFFFFFE,
        0xFFFFFF3,
        0xFFFFFF4,
        0xFFFFFF5,
        0xFFFFFF6,
        0xFFFFFF7,
        0xFFFFFF8,
        0xFFFFFF9,
        0xFFFFFFA,
        0xFFFFFFB,
        0x14,
        0x3F8,
        0x3F9,
        0xFFA,
        0x1FF9,
        0x15,
        0xF8,
        0x7FA,
        0x3FA,
        0x3FB,
        0xF9,
        0x7FB,
        0xFA,
        0x16,
        0x17,
        0x18,
        0x0,
        0x1,
        0x2,
        0x19,
        0x1A,
        0x1B,
        0x1C,
        0x1D,
        0x1E,
        0x1F,
        0x5C,
        0xFB,
        0x7FFC,
        0x20,
        0xFFB,
        0x3FC,
        0x1FFA,
        0x21,
        0x5D,
        0x5E,
        0x5F,
        0x60,
        0x61,
        0x62,
        0x63,
        0x64,
        0x65,
        0x66,
        0x67,
        0x68,
        0x69,
        0x6A,
        0x6B,
        0x6C,
        0x6D,
        0x6E,
        0x6F,
        0x70,
        0x71,
        0x72,
        0xFC,
        0x73,
        0xFD,
        0x1FFB,
        0x7FFF0,
        0x1FFC,
        0x3FFC,
        0x22,
        0x7FFD,
        0x3,
        0x23,
        0x4,
        0x24,
        0x5,
        0x25,
        0x26,
        0x27,
        0x6,
        0x74,
        0x75,
        0x28,
        0x29,
        0x2A,
        0x7,
        0x2B,
        0x76,
        0x2C,
        0x8,
        0x9,
        0x2D,
        0x77,
        0x78,
        0x79,
        0x7A,
        0x7B,
        0x7FFE,
        0x7FC,
        0x3FFD,
        0x1FFD,
        0xFFFFFFC,
        0xFFFE6,
        0x3FFFD2,
        0xFFFE7,
        0xFFFE8,
        0x3FFFD3,
        0x3FFFD4,
        0x3FFFD5,
        0x7FFFD9,
        0x3FFFD6,
        0x7FFFDA,
        0x7FFFDB,
        0x7FFFDC,
        0x7FFFDD,
        0x7FFFDE,
        0xFFFFEB,
        0x7FFFDF,
        0xFFFFEC,
        0xFFFFED,
        0x3FFFD7,
        0x7FFFE0,
        0xFFFFEE,
        0x7FFFE1,
        0x7FFFE2,
        0x7FFFE3,
        0x7FFFE4,
        0x1FFFDC,
        0x3FFFD8,
        0x7FFFE5,
        0x3FFFD9,
        0x7FFFE6,
        0x7FFFE7,
        0xFFFFEF,
        0x3FFFDA,
        0x1FFFDD,
        0xFFFE9,
        0x3FFFDB,
        0x3FFFDC,
        0x7FFFE8,
        0x7FFFE9,
        0x1FFFDE,
        0x7FFFEA,
        0x3FFFDD,
        0x3FFFDE,
        0xFFFFF0,
        0x1FFFDF,
        0x3FFFDF,
        0x7FFFEB,
        0x7FFFEC,
        0x1FFFE0,
        0x1FFFE1,
        0x3FFFE0,
        0x1FFFE2,
        0x7FFFED,
        0x3FFFE1,
        0x7FFFEE,
        0x7FFFEF,
        0xFFFEA,
        0x3FFFE2,
        0x3FFFE3,
        0x3FFFE4,
        0x7FFFF0,
        0x3FFFE5,
        0x3FFFE6,
        0x7FFFF1,
        0x3FFFFE0,
        0x3FFFFE1,
        0xFFFEB,
        0x7FFF1,
        0x3FFFE7,
        0x7FFFF2,
        0x3FFFE8,
        0x1FFFFEC,
        0x3FFFFE2,
        0x3FFFFE3,
        0x3FFFFE4,
        0x7FFFFDE,
        0x7FFFFDF,
        0x3FFFFE5,
        0xFFFFF1,
        0x1FFFFED,
        0x7FFF2,
        0x1FFFE3,
        0x3FFFFE6,
        0x7FFFFE0,
        0x7FFFFE1,
        0x3FFFFE7,
        0x7FFFFE2,
        0xFFFFF2,
        0x1FFFE4,
        0x1FFFE5,
        0x3FFFFE8,
        0x3FFFFE9,
        0xFFFFFFD,
        0x7FFFFE3,
        0x7FFFFE4,
        0x7FFFFE5,
        0xFFFEC,
        0xFFFFF3,
        0xFFFED,
        0x1FFFE6,
        0x3FFFE9,
        0x1FFFE7,
        0x1FFFE8,
        0x7FFFF3,
        0x3FFFEA,
        0x3FFFEB,
        0x1FFFFEE,
        0x1FFFFEF,
        0xFFFFF4,
        0xFFFFF5,
        0x3FFFFEA,
        0x7FFFF4,
        0x3FFFFEB,
        0x7FFFFE6,
        0x3FFFFEC,
        0x3FFFFED,
        0x7FFFFE7,
        0x7FFFFE8,
        0x7FFFFE9,
        0x7FFFFEA,
        0x7FFFFEB,
        0xFFFFFFE,
        0x7FFFFEC,
        0x7FFFFED,
        0x7FFFFEE,
        0x7FFFFEF,
        0x7FFFFF0,
        0x3FFFFEE,
        0x3FFFFFFF
    ];

    const HUFFMAN_CODE_LENGTHS = [
        13,
        23,
        28,
        28,
        28,
        28,
        28,
        28,
        28,
        24,
        30,
        28,
        28,
        30,
        28,
        28,
        28,
        28,
        28,
        28,
        28,
        28,
        30,
        28,
        28,
        28,
        28,
        28,
        28,
        28,
        28,
        28,
        6,
        10,
        10,
        12,
        13,
        6,
        8,
        11,
        10,
        10,
        8,
        11,
        8,
        6,
        6,
        6,
        5,
        5,
        5,
        6,
        6,
        6,
        6,
        6,
        6,
        6,
        7,
        8,
        15,
        6,
        12,
        10,
        13,
        6,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        7,
        8,
        7,
        8,
        13,
        19,
        13,
        14,
        6,
        15,
        5,
        6,
        5,
        6,
        5,
        6,
        6,
        6,
        5,
        7,
        7,
        6,
        6,
        6,
        5,
        6,
        7,
        6,
        5,
        5,
        6,
        7,
        7,
        7,
        7,
        7,
        15,
        11,
        14,
        13,
        28,
        20,
        22,
        20,
        20,
        22,
        22,
        22,
        23,
        22,
        23,
        23,
        23,
        23,
        23,
        24,
        23,
        24,
        24,
        22,
        23,
        24,
        23,
        23,
        23,
        23,
        21,
        22,
        23,
        22,
        23,
        23,
        24,
        22,
        21,
        20,
        22,
        22,
        23,
        23,
        21,
        23,
        22,
        22,
        24,
        21,
        22,
        23,
        23,
        21,
        21,
        22,
        21,
        23,
        22,
        23,
        23,
        20,
        22,
        22,
        22,
        23,
        22,
        22,
        23,
        26,
        26,
        20,
        19,
        22,
        23,
        22,
        25,
        26,
        26,
        26,
        27,
        27,
        26,
        24,
        25,
        19,
        21,
        26,
        27,
        27,
        26,
        27,
        24,
        21,
        21,
        26,
        26,
        28,
        27,
        27,
        27,
        20,
        24,
        20,
        21,
        22,
        21,
        21,
        23,
        22,
        22,
        25,
        25,
        24,
        24,
        26,
        23,
        26,
        27,
        26,
        26,
        27,
        27,
        27,
        27,
        27,
        28,
        27,
        27,
        27,
        27,
        27,
        26,
        30
    ];
}
