<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Events;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Util\Channel;

class EventSource
{
    protected $channel;

    public function __construct(int $bufferSize = 20)
    {
        $this->channel = new Channel($bufferSize);
    }

    public function close()
    {
        $this->channel->close();
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    public function send(string $message, string $event = null): Awaitable
    {
        $data = \str_replace("\n", "\ndata: ", $message);
        
        if ($event === null) {
            $event = '';
        } else {
            $event = "event: $event\n";
        }
        
        return $this->channel->send($event . "data: $data\n\n");
    }
}
