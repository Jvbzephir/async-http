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
 * The Accept request-header field can be used to specify certain media types which are acceptable for the response.
 * 
 * Accept headers can be used to indicate that the request is specifically limited to a small set of desired types, as in the case
 * of a request for an in-line image. 
 * 
 * @author Martin Schröder
 */
class AcceptHeader extends AbstractListHeader
{
    public function __construct(array $types = [])
    {
        $this->entries = $types;
    }
    
    public static function fromMessage(HttpMessage $message): AcceptHeader
    {
        $accept = $message->getHeaderLine('Accept');
        $types = [];
        
        foreach (Attributes::splitValues($accept) as $str) {
            if (false === ($index = strpos($str, ';'))) {
                $type = new ContentType(new MediaType($str), [
                    'q' => 1.0
                ]);
            } else {
                $type = new ContentType(new MediaType(substr($str, 0, $index)), array_merge([
                    'q' => 1.0
                ], Attributes::parseAttributes(substr($str, $index + 1))));
            }
            
            if (static::insertBasedOnQuality($type, $types)) {
                continue;
            }
            
            $types[] = $type;
        }
        
        return new static($types);
    }

    public function getContentTypes(): array
    {
        return $this->entries;
    }
}
