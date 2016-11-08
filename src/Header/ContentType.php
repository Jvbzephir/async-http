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
 * The Content-Type entity-header field indicates the media type of the entity-body sent to the recipient or, in
 * the case of the HEAD method, the media type that would have been sent had the request been a GET.
 * 
 * @author Martin Schröder
 */
class ContentType extends HeaderToken
{
    /**
     * Create a new content type.
     * 
     * @param string $type
     * @param array $params
     */
    public function __construct($type, array $params = [])
    {
        parent::__construct($type, $params);
        
        $this->value = new MediaType($type);
    }
    
    /**
     * Get the parsed media type.
     * 
     * @return MediaType
     */
    public function getMediaType(): MediaType
    {
        return $this->value;
    }
    
    /**
     * Get score of this type (needed to sort acceptable types).
     * 
     * @return int
     */
    public function getScore(): int
    {
        return $this->value->getScore();
    }
}
