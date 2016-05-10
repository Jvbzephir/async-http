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

use KoolKode\Util\MediaType;

/**
 * A content type as specified by an accept header.
 * 
 * @author Martin Schröder
 */
class ContentType implements AttributesInterface
{
    use AttributesTrait;
    
    protected $mediaType;

    public function __construct(MediaType $mediaType, array $attributes = [])
    {
        $this->mediaType = $mediaType;
        $this->attributes = $attributes;
    }

    public function __toString(): string
    {
        return $this->mediaType . static::buildAttributeString($this->attributes);
    }
    
    public function getMediaType(): MediaType
    {
        return $this->mediaType;
    }
    
    public function getScore(): int
    {
        return $this->mediaType->getScore();
    }
}
