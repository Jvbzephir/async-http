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

class HPackTest extends \PHPUnit_Framework_TestCase
{
    public function provideEncoderData()
    {
        yield [
            [
                [
                    ':method',
                    'GET'
                ]
            ],
            '82'
        ];
        
        yield [
            [
                [
                    ':path',
                    '/sample/path'
                ]
            ],
            '040c 2f73 616d 706c 652f 7061 7468'
        ];
    }
    
    /**
     * @dataProvider provideEncoderData
     */
    public function testEncoderWithKnownOutcome(array $headers, string $result)
    {
        $encoder = new HPack();
        $encoded = $encoder->encode($headers);
        
        $this->assertEquals($result, $this->convertToHexString($encoded));
        $this->assertEquals($headers, (new HPack())->decode($encoded));
    }
    
    public function testEncoder()
    {
        $encoder = new HPack();
        $decoder = new HPack();
        
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
                ':authority',
                'www.example.com'
            ],
            [
                'custom-key',
                'custom-value'
            ]
        ];
        
        $encoded = $encoder->encode($headers);
        
        $this->assertEquals($headers, $decoder->decode($encoded));
    }
    
    public function testDecoder()
    {
        $pack = new HPack();
        
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
        
        $this->assertEquals(0, $pack->getDynamicTableSize());
        $this->assertEquals($headers, $pack->decode($this->getHexString('8286 8441 0f77 7777 2e65 7861 6d70 6c65 2e63 6f6d')));
        $this->assertEquals(1, $pack->getDynamicTableSize());
        
        $headers[] = [
            'cache-control',
            'no-cache'
        ];
        
        $this->assertEquals(1, $pack->getDynamicTableSize());
        $this->assertEquals($headers, $pack->decode($this->getHexString('8286 84be 5808 6e6f 2d63 6163 6865')));
        $this->assertEquals(2, $pack->getDynamicTableSize());
        
        $headers[1] = [
            ':scheme',
            'https'
        ];
        $headers[2] = [
            ':path',
            '/index.html'
        ];
        
        array_pop($headers);
        $headers[] = [
            'custom-key',
            'custom-value'
        ];
        
        $this->assertEquals(2, $pack->getDynamicTableSize());
        $this->assertEquals($headers, $pack->decode($this->getHexString('8287 85bf 400a 6375 7374 6f6d 2d6b 6579 0c63 7573 746f 6d2d 7661 6c75 65')));
        $this->assertEquals(3, $pack->getDynamicTableSize());
    }

    public function testDecodeHuffman()
    {
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
        
        $decoder = new HPack();
        $this->assertEquals($headers, $decoder->decode($this->getHexString('8286 8441 8cf1 e3c2 e5f2 3a6b a0ab 90f4 ff')));
    }

    protected function convertToHexString(string $input): string
    {
        $hex = '';
        
        for ($len = strlen($input), $i = 0; $i < $len; $i++) {
            $hex .= str_pad(dechex(ord($input[$i])), 2, '0', STR_PAD_LEFT);
        }
        
        return implode(' ', str_split($hex, 4));
    }
    
    protected function getHexString(string $input): string
    {
        return implode('', array_map(function ($byte) {
            return chr(intval($byte, 16));
        }, str_split(str_replace(' ', '', $input), 2)));
    }
}
