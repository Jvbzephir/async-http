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

use Interop\Async\Loop;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\WebSocket\Connection;
use KoolKode\Async\Http\WebSocket\Endpoint;
use KoolKode\Async\Http\WebSocket\TextMessage;
use KoolKode\Async\Pause;

/**
 * Example endpoint that broadcasts text messages to all connected clients.
 */
class ExampleEndpoint extends Endpoint
{
    protected $info;

    protected $clients;

    public function onOpen(Connection $conn)
    {
        if (!$this->clients) {
            $this->clients = new \SplObjectStorage();
        }
        
        $this->clients->attach($conn);
        
        $this->info = new Coroutine(function () use ($conn) {
            while (true) {
                yield new Pause(1);
                yield $conn->sendText(json_encode([
                    'type' => 'system',
                    'info' => Loop::info()
                ]));
            }
        });
        
        yield $conn->sendText(json_encode([
            'type' => 'user',
            'message' => 'Hello Client :)'
        ]));
    }

    public function onClose(Connection $conn)
    {
        $this->clients->detach($conn);
        
        if ($this->info) {
            $this->info->cancel();
        }
    }

    public function onTextMessage(Connection $conn, TextMessage $message)
    {
        $json = json_encode([
            'type' => 'user',
            'message' => sprintf('Broadcast: "%s"', strtoupper((string) $message))
        ]);
        
        foreach ($this->clients as $client) {
            $client->sendText($json);
        }
    }
}
