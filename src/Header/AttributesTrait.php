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
 * Mixin with support for HTTP header attributes / params.
 * 
 * @author Martin Schröder
 */
trait AttributesTrait
{
    /**
     * Parsed HTTP attributes.
     * 
     * @var array
     */
    protected $attributes = [];
    
    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function getAttribute(string $name, $default = NULL)
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }
    
    public function getScore(): int
    {
        return 0;
    }
}
