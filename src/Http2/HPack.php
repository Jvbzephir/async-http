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
 * HPACK implementation.
 * 
 * @author Martin Schröder
 */
class HPack
{
    /**
     * Hard size limit for dynamic table.
     * 
     * @var integer
     */
    const SIZE_LIMIT = 4096;
    
    /**
     * Use dynamic table indexing in encoder.
     * 
     * @var bool
     */
    protected $useIndexing = true;
    
    /**
     * Max size of the dynamic table used by decoder.
     * 
     * @var int
     */
    protected $decoderTableMaxSize = 4096;
    
    /**
     * Size of the dynmic table used by decoder.
     *
     * @var int
     */
    protected $decoderTableSize = 0;
    
    /**
     * Dynamic table being used by decoder.
     * 
     * @var array
     */
    protected $decoderTable = [];
    
    /**
     * Size of the dynmic table used by encoder.
     * 
     * @var int
     */
    protected $encoderTableSize = 0;
    
    /**
     * Max size of the dynamic table used by encoder.
     * 
     * @var int
     */
    protected $encoderTableMaxSize = 4096;
    
    /**
     * Dynamic table being used by encoder.
     * 
     * @var array
     */
    protected $encoderTable = [];
    
    /**
     * HPACK context.
     * 
     * @var HPackContext
     */
    protected $context;
    
    /**
     * Create a new HPACK encoder / decoder.
     * 
     * @param HuffmanDecoder $decoder
     */
    public function __construct(HPackContext $context = NULL)
    {
        $this->context = $context ?? HPackContext::getDefaultContext();
    }
    
    /**
     * Enable / disable usage of dynamic table in encoder.
     * 
     * @param bool $useIndexing
     */
    public function setUseIndexing(bool $useIndexing)
    {
        $this->useIndexing = $useIndexing;
    }
    
    /**
     * Get the current size of the dynamic table.
     * 
     * @return int
     */
    public function getDecoderTableSize(): int
    {
        return count($this->decoderTable);
    }
    
    /**
     * Encode the given HTTP headers using HPACK header compression.
     * 
     * Eeach header must be an array, element 0 must be the lowercased header name, element 1 holds the value.
     * 
     * @param array $headers
     * @return string
     */
    public function encode(array $headers): string
    {
        $result = '';
        
        foreach ($headers as list ($k, $v)) {
            $index = self::STATIC_TABLE_LOOKUP[$k . ':' . $v] ?? NULL;
            
            if ($index !== NULL) {
                // Indexed Header Field
                if ($index < 0x7F) {
                    $result .= chr($index | 0x80);
                } else {
                    $result .= "\xFF" . $this->encodeInt($index - 0x7F);
                }
                
                continue;
            }
            
            $index = self::STATIC_TABLE_LOOKUP[$k] ?? NULL;
            $encoding = $this->context->getEncodingType($k);
            
            if ($this->useIndexing && $encoding === HPackContext::ENCODING_INDEXED) {
                foreach ($this->encoderTable as $i => $header) {
                    if ($header[0] === $k && $header[1] === $v) {
                        $i += self::STATIC_TABLE_SIZE + 1;
                        
                        // Indexed Header Field
                        if ($i < 0x7F) {
                            $result .= chr($i | 0x80);
                        } else {
                            $result .= "\xFF" . $this->encodeInt($i - 0x7F);
                        }
                        
                        continue 2;
                    }
                }
                
                array_unshift($this->encoderTable, [
                    $k,
                    $v
                ]);
                
                $this->encoderTableSize += 32 + \strlen($k) + \strlen($v);
                
                while ($this->encoderTableSize > $this->decoderTableMaxSize) {
                    list ($name, $value) = array_pop($this->encoderTable);
                    $this->encoderTableSize -= 32 + \strlen($name) + \strlen($value);
                }
                
                if ($index !== NULL) {
                    // Literal Header Field with Incremental Indexing — Indexed Name
                    if ($index < 0x40) {
                        $result .= chr($index | 0x40);
                    } else {
                        $result .= "\x7F" . $this->encodeInt($index - 0x40);
                    }
                } else {
                    // Literal Header Field with Incremental Indexing — New Name
                    $result .= "\x40" . $this->encodeString($k);
                }
            } elseif ($index !== NULL) {
                // Literal Header Field without Indexing / never indexed — Indexed Name
                if ($index < 0x10) {
                    $result .= chr($index | (($encoding === HPackContext::ENCODING_NEVER_INDEXED) ? 0x10 : 0x00));
                } else {
                    $result .= (($encoding === HPackContext::ENCODING_NEVER_INDEXED) ? "\x1F" : "\x0F") . $this->encodeInt($index - 0x0F);
                }
            } else {
                // Literal Header Field without Indexing / never indexed — New Name
                $result .= (($encoding === HPackContext::ENCODING_NEVER_INDEXED) ? "\x10" : "\x00") . $this->encodeString($k);
            }
            
            $result .= $this->encodeString($v);
        }
        
        return $result;
    }
    
    /**
     * Encode an integer value according to HPACK Integer Representation.
     *
     * @param int $int
     * @return string
     */
    protected function encodeInt(int $int): string
    {
        $result = '';
        $i = 0;
        
        while (($int >> $i) > 0x80) {
            $result .= chr(0x80 | (($int >> $i) & 0x7F));
            $i += 7;
        }
        
        return $result . chr($int >> $i);
    }
    
    /**
     * Encode a string literal.
     * 
     * @param string $input
     * @return string
     */
    protected function encodeString(string $input): string
    {
        if ($this->context->isCompressionEnabled()) {
            $input = $this->context->getHuffmanEncoder()->encode($input);
            
            if (\strlen($input) < 0x7F) {
                return chr(\strlen($input) | 0x80) . $input;
            }
            
            return "\xFF" . $this->encodeInt(\strlen($input) - 0x7F) . $input;
        }
        
        if (\strlen($input) < 0x7F) {
            return chr(\strlen($input)) . $input;
        }
        
        return "\x7F" . $this->encodeInt(\strlen($input) - 0x7F) . $input;
    }
    
    /**
     * Decode the given HPACK-encoded HTTP headers.
     * 
     * Returns an array of headers, each header is an array, element 0 is the name of the header and element 1 the value.
     * 
     * @param string $encoded
     * @return array
     * 
     * @throws \RuntimeException
     */
    public function decode(string $encoded): array
    {
        $headers = [];
        $encodedLength = \strlen($encoded);
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
                    
                    if (!isset($this->decoderTable[$index])) {
                        throw new \RuntimeException(sprintf('Missing index %X in dynamic table', $index));
                    }
                    
                    $headers[] = $this->decoderTable[$index];
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
                        $name = $this->decoderTable[$index - self::STATIC_TABLE_SIZE][0];
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
                    array_unshift($this->decoderTable, $header);
                    $this->decoderTableSize += 32 + \strlen($header[0]) + \strlen($header[1]);
                    
                    if ($this->decoderTableMaxSize < $this->decoderTableSize) {
                        $this->resizeDynamicTable();
                    }
                }
                
                continue;
            }
            
            // Dynamic Table Size Update
            if ($index == 0x3F) {
                $index = $this->decodeInt($encoded, $offset) + 0x40;
                
                if ($index > self::SIZE_LIMIT) {
                    throw new \RuntimeException(sprintf('Attempting to resize dynamic table to %u, limit is %u', $index, self::SIZE_LIMIT));
                }
                
                $this->resizeDynamicTable($index);
            }
        }
        
        return $headers;
    }
    
    /**
     * Resize HPACK dynamic table.
     * 
     * @param int $maxSize
     */
    protected function resizeDynamicTable(int $maxSize = NULL)
    {
        if ($maxSize !== NULL) {
            $this->decoderTableMaxSize = $maxSize;
        }
        
        while ($this->decoderTableSize > $this->decoderTableMaxSize) {
            list ($k, $v) = array_pop($this->decoderTable);
            $this->decoderTableSize -= 32 + \strlen($k) + \strlen($v);
        }
    }
    
    /**
     * Decode an HPACK-encoded integer.
     * 
     * @param string $encoded
     * @param int $offset
     * @return int
     */
    protected function decodeInt(string $encoded, int & $offset): int
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
    
    /**
     * Decode an HPACK String Literal.
     * 
     * Huffman-encoded string literals are supported.
     * 
     * @param string $encoded
     * @param int $encodedLength
     * @param int $offset
     * @return string
     * 
     * @throws \RuntimeException
     */
    protected function decodeString(string $encoded, int $encodedLength, int & $offset): string
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
                return $this->context->getHuffmanDecoder()->decode(substr($encoded, $offset, $len));
            }
            
            return substr($encoded, $offset, $len);
        } finally {
            $offset += $len;
        }
    }
    
    /**
     * Size of the static table.
     * 
     * @var int
     */
    const STATIC_TABLE_SIZE = 61;

    /**
     * Static table, indexing starts at 1!
     * 
     * @var array
     */
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

    /**
     * Lookup table being used to find indexes within the static table without using a loop.
     * 
     * @var array
     */
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
        0x7FFFFEB, 0xFFFFFFE, 0x7FFFFEC, 0x7FFFFED, 0x7FFFFEE, 0x7FFFFEF, 0x7FFFFF0, 0x3FFFFEE,
        0x3FFFFFFF
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
        27, 28, 27, 27, 27, 27, 27, 26,
        30
    ];
}
