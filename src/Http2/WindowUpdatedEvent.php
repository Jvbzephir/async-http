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

use KoolKode\Async\Event\Event;

/**
 * Is triggered whenever a WINDOW_UPDATE frame has been received and applied.
 * 
 * @author Martin Schröder
 */
class WindowUpdatedEvent extends Event
{
    /**
     * The increment as specified by the frame.
     * 
     * @var int
     */
    public $increment;
    
    /**
     * Create a window update event.
     * 
     * @param int $increment Increment as specified by frame.
     */
    public function __construct(int $increment)
    {
        $this->increment = $increment;
    }
}
