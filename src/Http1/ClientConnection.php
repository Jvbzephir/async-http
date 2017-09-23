<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Stream\DuplexStream;

class ClientConnection
{
    public $key;
    
    public $stream;
    
    public $remaining;
    
    public $expires;
    
    public function __construct(string $key, DuplexStream $stream)
    {
        $this->key = $key;
        $this->stream = $stream;
        $this->remaining = 100;
        $this->expires = \time() + 30;
    }
    
    public function close(): void
    {
        $this->stream->close();
    }
}
