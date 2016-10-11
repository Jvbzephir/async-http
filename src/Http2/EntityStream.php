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

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Stream\ReadableChannelStream;
use KoolKode\Async\Util\Channel;

class EntityStream extends ReadableChannelStream
{
    protected $conn;

    protected $id;

    public function __construct(Channel $channel, Connection $conn, int $id)
    {
        parent::__construct($channel);
        
        $this->conn = $conn;
        $this->id = $id;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): Awaitable
    {
        $this->channel->close();
        
        $close = parent::close();
        
        $close->when(function () {
            $this->conn->closeStream($this->id);
        });
        
        return $close;
    }
}
