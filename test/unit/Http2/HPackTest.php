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
 * @covers \KoolKode\Async\Http\Http2\HPack
 */
class HPackTest extends \PHPUnit_Framework_TestCase
{
    /**
     * C.1.1 Example 1: Encoding 10 Using a 5-Bit Prefix
     */
    public function testIntegerEncoding5BitPrefix()
    {
        $this->assertEquals(0b00001010, ord((new HPack())->encodeInt(10)));
    }
    
    /**
     * C.1.2 Example 2: Encoding 1337 Using a 5-Bit Prefix
     */
    public function testIntegerEncoding5BitPrefix2()
    {
        $encoded = (new HPack())->encodeInt(1337 - 31);
        
        $this->assertEquals([
            0b10011010,
            0b00001010
        ], array_map('ord', str_split($encoded, 1)));
    }
    
    /**
     * C.1.3 Example 3: Encoding 42 Starting at an Octet Boundary
     */
    public function testIntegerEncodingAtOctetBoundary()
    {
        $this->assertEquals(0b00101010, ord((new HPack())->encodeInt(42)));
    }
    
    /**
     * C.2.1 Literal Header Field with Indexing 
     */
    public function testEncodeHeaderFieldWithIndexing()
    {
        $context = new HPackContext();
        $context->setEncodingType('custom-key', HPackContext::ENCODING_INDEXED);
        
        $hpack = new HPack($context);
        $hpack->setCompressStrings(false);
        
        $headers = [
            [
                'custom-key',
                'custom-header'
            ]
        ];
        
        $encoded = $hpack->encode($headers);
        
        $this->assertEquals("\x40\x0a\x63\x75\x73\x74\x6f\x6d\x2d\x6b\x65\x79\x0d\x63\x75\x73\x74\x6f\x6d\x2d\x68\x65\x61\x64\x65\x72", $encoded);

        $this->assertEquals($headers, $hpack->decode($encoded));
    }
    
    /**
     * C.2.2 Literal Header Field without Indexing
     */
    public function testCanEncodeHeaderFieldWithoutIndexing()
    {
        $hpack = new HPack();
        $hpack->setCompressStrings(false);
        
        $headers = [
            [
                ':path',
                '/sample/path'
            ]
        ];
        
        $encoded = $hpack->encode($headers);
        
        $this->assertEquals("\x04\x0c\x2f\x73\x61\x6d\x70\x6c\x65\x2f\x70\x61\x74\x68", $encoded);
        
        $this->assertEquals($headers, $hpack->decode($encoded));
    }
    
    /**
     * C.2.3 Literal Header Field Never Indexed 
     */
    public function testCanEncodeNeverIndexedField()
    {
        $context = new HPackContext();
        $context->setEncodingType('password', HPackContext::ENCODING_NEVER_INDEXED);
        
        $hpack = new HPack($context);
        $hpack->setCompressStrings(false);
        
        $headers = [
            [
                'password',
                'secret'
            ]
        ];
        
        $encoded = $hpack->encode($headers);
        
        $this->assertEquals("\x10\x08\x70\x61\x73\x73\x77\x6f\x72\x64\x06\x73\x65\x63\x72\x65\x74", $encoded);
        
        $this->assertEquals($headers, $hpack->decode($encoded));
    }
    
    /**
     * C.2.4 Indexed Header Field
     */
    public function testCanEncodeIndexedHeaderField()
    {
        $hpack = new HPack();
        $headers = [
            [
                ':method',
                'GET'
            ]
        ];
        
        $encoded = $hpack->encode($headers);
        
        $this->assertEquals(0x82, ord($encoded));
        
        $this->assertEquals($headers, $hpack->decode($encoded));
    }
    
    /**
     * C.4 Request Examples with Huffman Coding
     */
    public function testMultipleRequestsWithHuffmanCoding()
    {
        $context = new HPackContext();
        $context->setEncodingType(':authority', HPackContext::ENCODING_INDEXED);
        $context->setEncodingType('cache-control', HPackContext::ENCODING_INDEXED);
        $context->setEncodingType('custom-key', HPackContext::ENCODING_INDEXED);
        
        $sender = new HPack($context);
        $receiver = new HPack();
        
        $headers = [
            [
                ':method',
                'GET'
            ],
            [
                ':scheme',
                'http'
            ],
            [
                ':path',
                '/'
            ],
            [
                ':authority',
                'www.example.com'
            ]
        ];
        
        $encoded = $sender->encode($headers);
        
        $this->assertEquals("\x82\x86\x84\x41\x8c\xf1\xe3\xc2\xe5\xf2\x3a\x6b\xa0\xab\x90\xf4\xff", $encoded);
        
        $this->assertEquals($headers, $receiver->decode($encoded));
        
        // Second request
        
        $headers[] = [
            'cache-control',
            'no-cache'
        ];
        
        $encoded = $sender->encode($headers);
        
        $this->assertEquals("\x82\x86\x84\xbe\x58\x86\xa8\xeb\x10\x64\x9c\xbf", $encoded);
        
        $this->assertEquals($headers, $receiver->decode($encoded));
        
        // Third request
        $headers[1][1] = 'https';
        $headers[2][1] = '/index.html';
        
        unset($headers[4]);
        $headers = array_values($headers);
        
        $headers[] = [
            'custom-key',
            'custom-value'
        ];
        
        $encoded = $sender->encode($headers);
        
        $this->assertEquals("\x82\x87\x85\xbf\x40\x88\x25\xa8\x49\xe9\x5b\xa9\x7d\x7f\x89\x25\xa8\x49\xe9\x5b\xb8\xe8\xb4\xbf", $encoded);
        
        $this->assertEquals($headers, $receiver->decode($encoded));
    }
}
