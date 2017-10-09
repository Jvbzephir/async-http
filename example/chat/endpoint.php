<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

use KoolKode\Async\Context;
use KoolKode\Async\Http\WebSocket\Connection;
use KoolKode\Async\Http\WebSocket\WebSocketEndpoint;

return new class() extends WebSocketEndpoint {

    protected $connections;

    public function __construct()
    {
        $this->connections = new \SplObjectStorage();
    }

    public function negotiateProtocol(array $protocols): string
    {
        return \in_array('chat-example', $protocols, true) ? 'chat-example' : '';
    }

    public function onOpen(Context $context, Connection $conn)
    {
        yield from $this->broadcast($context, 'Client connected');
        
        $this->connections->attach($conn);
    }

    public function onClose(Context $context, Connection $conn, ?\Throwable $e = null)
    {
        if ($this->connections->contains($conn)) {
            $this->connections->detach($conn);
            
            yield from $this->broadcast($context, 'Client disconnected');
        }
    }

    public function onTextMessage(Context $context, Connection $conn, string $message)
    {
        yield from $this->broadcast($context, strtoupper($message));
    }

    protected function broadcast(Context $context, string $message): \Generator
    {
        $message = json_encode([
            'type' => 'user',
            'message' => $message
        ]);
        
        foreach ($this->connections as $receiver) {
            yield $receiver->sendText($context, $message);
        }
    }
};
