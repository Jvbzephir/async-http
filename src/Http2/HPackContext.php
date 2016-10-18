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

    public static function createClientContext(): HPackContext
    {
        static $indexed = [
            'accept',
            'accept-charset',
            'accept-encoding',
            'accept-language',
            'cache-control',
            'content-type',
            'dnt',
            'host',
            'pragma',
            'user-agent',
            'x-requested-with'
        ];
        
        $context = new HPackContext();
        
        foreach ($indexed as $name) {
            $context->encodings[$name] = self::ENCODING_INDEXED;
        }
        
        return $context;
    }

    public static function createServerContext(): HPackContext
    {
        static $indexed = [
            'cache-control',
            'content-encoding',
            'content-type',
            'p3p',
            'server',
            'vary',
            'x-frame-options',
            'x-xss-protection',
            'x-content-type-options',
            'x-ua-compatible'
        ];
        
        $context = new HPackContext();
        
        foreach ($indexed as $name) {
            $context->encodings[$name] = self::ENCODING_INDEXED;
        }
        
        return $context;
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
}
