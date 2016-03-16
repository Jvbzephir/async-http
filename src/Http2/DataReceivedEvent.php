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

use KoolKode\Async\Event\StoredEvent;

class DataReceivedEvent extends StoredEvent
{
    public $data;
    
    public $eof;
    
    public function __construct(string $data, bool $eof = false)
    {
        $this->data = $data;
        $this->eof = $eof;
    }
}
