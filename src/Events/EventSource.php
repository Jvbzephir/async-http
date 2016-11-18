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

/**
 * Source of Server-Sent events (SSE).
 * 
 * @link https://html.spec.whatwg.org/multipage/comms.html#server-sent-events
 * 
 * @author Martin Schröder
 */
class EventSource
{
    /**
     * Channel that queues outgoing messages.
     * 
     * @var Channel
     */
    protected $channel;

    /**
     * Create a new SSE source.
     * 
     * @param int $bufferSize Buffer size for outgoing messages.
     */
    public function __construct(int $bufferSize = 20)
    {
        $this->channel = new Channel($bufferSize);
    }

    /**
     * Closes the event source.
     */
    public function close()
    {
        $this->channel->close();
    }

    /**
     * Get the channel being used to queue outgoing messages.
     * 
     * @return Channel
     */
    public function getChannel(): Channel
    {
        return $this->channel;
    }

    /**
     * Send an event to the client.
     * 
     * @param string $message The message payload to be sent.
     * @param string $event Type of the event.
     * 
     * @throws \InvalidArgumentException When the given message is not a valid UTF-8 string.
     */
    public function send(string $message, string $event = null): Awaitable
    {
        if (!\preg_match('//u', $message)) {
            throw new \InvalidArgumentException('SSE messages must be encoded as UTF-8 strings');
        }
        
        $data = \str_replace("\n", "\ndata: ", $message);
        
        if ($event === null) {
            $event = '';
        } else {
            $event = "event: $event\n";
        }
        
        return $this->channel->send($event . "data: $data\n\n");
    }
}
