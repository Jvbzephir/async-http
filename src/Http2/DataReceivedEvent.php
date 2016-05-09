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
 * Is triggered whenever a DATA frame has been received by an HTTP/2 stream.
 * 
 * @author Martin Schröder
 */
class DataReceivedEvent extends Event
{
    /**
     * Received data (without header and padding).
     * 
     * @var string
     */
    public $data;
    
    /**
     * set to true when the DATA frame provides the END_STREAM flag.
     * 
     * @var bool
     */
    public $eof;
    
    /**
     * DATA frame has been received.
     * 
     * @param string $data
     * @param bool $eof
     */
    public function __construct(string $data, bool $eof = false)
    {
        $this->data = $data;
        $this->eof = $eof;
    }
}
