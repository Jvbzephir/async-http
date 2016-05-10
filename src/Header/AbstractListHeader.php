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
 * Base class for implementing all kinds of Accept headers. 
 * 
 * @author Martin Schröder
 */
abstract class AbstractListHeader implements \Countable, \IteratorAggregate
{
    protected $entries = [];

    public function __toString(): string
    {
        return implode(', ', $this->entries);
    }

    public function count()
    {
        return count($this->entries);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->entries);
    }

    protected static function insertBasedOnQuality(AttributesInterface $entry, array & $entries): bool
    {
        $q = (float) min(1, max(0, (float) $entry->getAttribute('q', 1)));
        
        for ($size = count($entries), $i = 0; $i < $size; $i++) {
            $tmp = (float) $entries[$i]->getAttribute('q', 1.0);
            
            if ($q > $tmp) {
                array_splice($entries, $i, 0, [
                    $entry
                ]);
                
                return true;
            }
            
            if ($q == $tmp) {
                return static::insertBasedOnScore($entry, $entries, $i);
            }
        }
        
        return false;
    }

    protected static function insertBasedOnScore(AttributesInterface $entry, array & $entries, int $i): bool
    {
        $s = $entry->getScore();
        $tmp = $entries[$i]->getScore();
        
        if ($s > $tmp) {
            array_splice($entries, $i, 0, [
                $entry
            ]);
            
            return true;
        }
        
        if ($s == $tmp) {
            return static::insertBasedOnAttributeCount($entry, $entries, $i);
        }
        
        return false;
    }

    protected static function insertBasedOnAttributeCount(AttributesInterface $entry, array & $entries, int $i): bool
    {
        if (count($entry->getAttributes()) > count($entries[$i]->getAttributes())) {
            array_splice($entries, $i, 0, [
                $entry
            ]);
            
            return true;
        }
        
        return false;
    }
}
