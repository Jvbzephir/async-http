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
 * The Accept request-header field can be used to specify certain media types which are acceptable for the response.
 * 
 * Accept headers can be used to indicate that the request is specifically limited to a small set of desired types, as in
 * the case of a request for an in-line image. 
 * 
 * @author Martin Schröder
 */
class Accept implements \Countable, \IteratorAggregate
{
    /**
     * Acceptable media types.
     * 
     * @var array
     */
    protected $types = [];
    
    /**
     * Create an Accept header accepting the given media types.
     * 
     * @param HeaderToken ...$types
     */
    public function __construct(HeaderToken ...$types)
    {
        $this->types = $this->sortByQuality($types);
    }
    
    /**
     * Count the number of accepted media types.
     */
    public function count()
    {
        return \count($this->types);
    }

    /**
     * Iteratoe over the accepted content types.
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->types);
    }
    
    /**
     * Check if the given media type is accepted.
     * 
     * @param string $type
     * @return bool
     */
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
    
    /**
     * Get all accepted media types.
     * 
     * @return array
     */
    public function getMediaTypes(): array
    {
        return \array_map(function (ContentType $type) {
            return $type->getMediaType();
        }, $this->types);
    }

    /**
     * Stable sort implementation that order by relative quality and media type score.
     * 
     * @param array $types
     * @return array
     */
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
