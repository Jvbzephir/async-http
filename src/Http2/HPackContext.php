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

use KoolKode\Util\HuffmanCode;
use KoolKode\Util\HuffmanDecoder;
use KoolKode\Util\HuffmanEncoder;

/**
 * Provides shared context that can be used by multiple HPACK coder instances.
 * 
 * @author Martin Schröder
 */
class HPackContext
{
    const ENCODING_LITERAL = 0;
    
    const ENCODING_INDEXED = 1;
    
    const ENCODING_NEVER_INDEXED = 2;
    
    protected $compressionEnabled;

    protected $encodings = [];
    
    protected $huffmanCode;
    
    protected $huffmanEncoder;

    protected $huffmanDecoder;
    
    protected static $defaultContext;

    public function __construct(bool $compressionEnabled = true)
    {
        $this->compressionEnabled = $compressionEnabled;
    }
    
    public static function getDefaultContext(): HPackContext
    {
        if (static::$defaultContext === NULL) {
            static::$defaultContext = new static();
        }
        
        return static::$defaultContext;
    }

    public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }
    
    public function setCompressionEnabled(bool $compressionEnabled)
    {
        $this->compressionEnabled = $compressionEnabled;
    }

    public function getEncodingType(string $name): int
    {
        return $this->encodings[$name] ?? self::ENCODING_LITERAL;
    }
    
    public function setEncodingType(string $name, int $encoding)
    {
        switch ($encoding) {
            case self::ENCODING_INDEXED:
            case self::ENCODING_LITERAL:
            case self::ENCODING_NEVER_INDEXED:
                break;
            default:
                throw new \InvalidArgumentException(\sprintf('Invalid header encoding type: "%s"', $encoding));
        }
        
        $this->encodings[$name] = $encoding;
    }
    
    public function getHuffmanEncoder(): HuffmanEncoder
    {
        if ($this->huffmanEncoder === NULL) {
            if ($this->huffmanCode === NULL) {
                $this->huffmanCode = $this->createHuffmanCode();
            }
            
            $this->huffmanEncoder = new HuffmanEncoder($this->huffmanCode);
        }
        
        return $this->huffmanEncoder;
    }

    public function getHuffmanDecoder(): HuffmanDecoder
    {
        if ($this->huffmanDecoder === NULL) {
            if ($this->huffmanCode === NULL) {
                $this->huffmanCode = $this->createHuffmanCode();
            }
            
            $this->huffmanDecoder = new HuffmanDecoder($this->huffmanCode, true);
        }
        
        return $this->huffmanDecoder;
    }

    /**
     * Create Huffman code used by HPACK.
     *
     * @return HuffmanCode
     */
    protected function createHuffmanCode(): HuffmanCode
    {
        $huffman = new HuffmanCode();
        
        foreach (HPack::HUFFMAN_CODE as $i => $code) {
            $huffman->addCode(($i > 255) ? '' : \chr($i), $code, HPack::HUFFMAN_CODE_LENGTHS[$i]);
        }
        
        return $huffman;
    }
}
