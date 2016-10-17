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
 * Provides shared context that can be used by multiple HPACK coder instances.
 * 
 * @author Martin Schröder
 */
class HPackContext
{
    const ENCODING_LITERAL = 0;
    
    const ENCODING_INDEXED = 1;
    
    const ENCODING_NEVER_INDEXED = 2;
    
    protected $encodings = [];

    protected $compressionEnabled;
    
    protected $compressor;
    
    public function __construct(bool $compressionEnabled = true)
    {
        $this->compressionEnabled = $compressionEnabled;
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
    
    public function getCompressor(): HPackCompressor
    {
        if ($this->compressor === null) {
            $this->compressor = new HPackCompressor();
        }
        
        return $this->compressor;
    }
}
