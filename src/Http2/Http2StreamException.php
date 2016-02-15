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

use KoolKode\Async\Stream\StreamException;

/**
 * A stream error is an error related to a specific stream that does not affect processing of other streams.
 * 
 * @author Martin Schröder
 */
class Http2StreamException extends StreamException
{
    protected $streamId;
    
    public function setStreamId(int $id): StreamException
    {
        return $this;
    }
}
