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

use KoolKode\Async\Http\HttpMessage;
use KoolKode\Util\MediaType;

/**
 * The Content-Type entity-header field indicates the media type of the entity-body sent to the recipient or, in the case of
 * the HEAD method, the media type that would have been sent had the request been a GET.
 * 
 * @author Martin Schröder
 */
class ContentTypeHeader implements AttributesInterface
{
    use AttributesTrait;
    
    /**
     * Parsed media type.
     * 
     * @var MediaType
     */
    protected $mediaType;

    public function __construct(MediaType $mediaType, array $attributes = [])
    {
        $this->mediaType = $mediaType;
        $this->attributes = $attributes;
    }

    public function __toString(): string
    {
        return $this->mediaType . Attributes::buildAttributeString($this->attributes);
    }

    public static function fromMessage(HttpMessage $message): ContentTypeHeader
    {
        $type = $message->getHeaderLine('Content-Type') ?: 'application/octet-stream';
        $attr = [];
        
        if (false !== ($index = strpos($type, ';'))) {
            $attr = Attributes::parseAttributes(substr($type, $index + 1));
            $type = rtrim(substr($type, 0, $index));
        }
        
        return new static(new MediaType($type), $attr);
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
