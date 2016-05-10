<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Header;

/**
 * An acceptable encoding as specified by an Accept-Encoding header.
 * 
 * @author Martin Schröder
 */
class AcceptEncoding implements AttributesInterface
{
    use AttributesTrait;
    
    protected $encoding;

    public function __construct(string $encoding, array $attributes = [])
    {
        $this->encoding = strtolower($encoding);
        $this->attributes = $attributes;
    }

    public function __toString(): string
    {
        return $this->encoding . Attributes::buildAttributeString($this->attributes);
    }
    
    public function getName(): string
    {
        return $this->encoding;
    }
}
