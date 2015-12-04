<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http2;

class WindowUpdatedEvent
{
    public $increment;
    
    public function __construct(int $increment)
    {
        $this->increment = $increment;
    }
}
