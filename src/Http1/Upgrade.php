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

class Upgrade
{
    public $protocols;
    
    public $stream;
    
    public function __construct(DuplexStream $stream, string ...$protocols)
    {
        $this->stream = $stream;
        $this->protocols = $protocols;
    }
}
