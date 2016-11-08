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

class ContentType extends HeaderToken
{
    public function __construct($type, array $params = [])
    {
        parent::__construct($type, $params);
        
        $this->value = new MediaType($type);
    }
    
    public function getMediaType(): MediaType
    {
        return $this->value;
    }
    
    public function getScore(): int
    {
        return $this->value->getScore();
    }
}
