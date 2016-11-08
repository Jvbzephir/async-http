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

class Accept implements \Countable, \IteratorAggregate
{
    protected $types = [];
    
    public function __construct(HeaderToken ...$types)
    {
        $this->types = $this->sortByQuality($types);
    }
    
    public static function fromMessage(HttpMessage $message): Accept
    {
        return new static(...ContentType::parseList($message->getHeaderLine('Accept')));
    }
    
    public function count()
    {
        return \count($this->types);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->types);
    }
    
    public function accepts($type): bool
    {
        if (!$type instanceof MediaType) {
            $type = new MediaType($type);
        }
        
        foreach ($this->types as $candidate) {
            if ($candidate->getMediaType()->is($type)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function getMediaTypes(): array
    {
        return \array_map(function (ContentType $type) {
            return $type->getMediaType();
        }, $this->types);
    }

    protected function sortByQuality(array $types): array
    {
        $result = [];
        
        foreach ($types as $type) {
            if (!$type instanceof ContentType) {
                $type = new ContentType($type->getValue(), $type->getParams());
            }
            
            $priority = \max(0, \min(1, (float) $type->getParam('q', 1)));
            
            for ($size = \count($result), $i = 0; $i < $size; $i++) {
                $insert = ($priority > $result[$i][1]);
                
                if (!$insert && $priority == $result[$i][1] && $type->getScore() > $result[$i][0]->getScore()) {
                    $insert = true;
                }
                
                if ($insert) {
                    \array_splice($result, $i, 0, [
                        [
                            $type,
                            $priority
                        ]
                    ]);
                    
                    continue 2;
                }
            }
            
            $result[] = [
                $type,
                $priority
            ];
        }
        
        return \array_column($result, 0);
    }
}
