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

namespace KoolKode\Async\Http\WebSocket;

use KoolKode\Async\Awaitable;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Stream\ReadableStream;

class BinaryMessage
{
    protected $stream;

    public function __construct(ReadableStream $stream)
    {
        $this->stream = $stream;
    }

    public function getStream(): ReadableStream
    {
        return $this->stream;
    }

    public function getContents(): Awaitable
    {
        return new ReadContents($this->stream);
    }
}
