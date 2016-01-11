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
class AcceptHeader implements \Countable, \IteratorAggregate
{
    use AttributesTrait;
    
    protected $types;

    public function __construct(array $types = [])
    {
        $this->types = $types;
    }

    public function __toString(): string
    {
        return implode(', ', $this->types);
    }
    
    public function count()
    {
        return count($this->types);
    }
    
    public function getIterator()
    {
        return new \ArrayIterator($this->types);
    }

    public static function fromMessage(HttpMessage $message): AcceptHeader
    {
        $accept = $message->getHeaderLine('Accept');
        $types = [];
        
        foreach (static::splitValues($accept) as $str) {
            if (false === ($index = strpos($str, ';'))) {
                $type = new ContentType(new MediaType($str), [
                    'q' => 1.0
                ]);
            } else {
                $type = new ContentType(new MediaType(substr($str, 0, $index)), array_merge([
                    'q' => 1.0
                ], static::parseAttributes(substr($str, $index + 1))));
            }
            
            $q = (float) min(1, max(0, (float) $type->getAttribute('q', 1)));
            
            for ($size = count($types), $i = 0; $i < $size; $i++) {
                $tmp = (float) $types[$i]->getAttribute('q', 1.0);
                
                if ($q > $tmp) {
                    array_splice($types, $i, 0, [
                        $type
                    ]);
                    
                    continue 2;
                }
                
                if ($q == $tmp) {
                    $s = $type->getMediaType()->getScore();
                    $tmp = $types[$i]->getMediaType()->getScore();
                    
                    if ($s > $tmp) {
                        array_splice($types, $i, 0, [
                            $type
                        ]);
                        
                        continue 2;
                    }
                    
                    if ($s == $tmp) {
                        if (count($type->getAttributes()) > count($types[$i]->getAttributes())) {
                            array_splice($types, $i, 0, [
                                $type
                            ]);
                            
                            continue 2;
                        }
                    }
                }
            }
            
            $types[] = $type;
        }
        
        return new static($types);
    }
    
    public function getContentTypes(): array
    {
        return $this->types;
    }
}
