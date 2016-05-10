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
 * HTTP Header value attribute accessors.
 * 
 * @author Martin Schröder
 */
interface AttributesInterface
{
    public function hasAttribute(string $name): bool;
    
    public function getAttribute(string $name, $default = NULL);
    
    public function getAttributes(): array;
    
    public function getScore(): int;
}
