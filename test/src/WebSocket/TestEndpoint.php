<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\WebSocket;

use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\ReadableStream;

/**
 * WebSocket endpoint that allows using callbacks as message handlers.
 * 
 * Using callbacks allows for convenient assertions within a message handler.
 * 
 * @author Martin Schröder
 */
class TestEndpoint extends Endpoint
{
    protected $handshake;

    protected $textHandler;
    
    protected $binaryHandler;

    public function validateHandshake(callable $handshake)
    {
        $this->handshake = $handshake;
    }

    public function onHandshake(HttpRequest $request, HttpResponse $response): HttpResponse
    {
        if ($this->handshake) {
            ($this->handshake)($request, $response);
        }
        
        return $response;
    }

    public function handleTextMessage(callable $handler)
    {
        $this->textHandler = $handler;
    }

    public function onTextMessage(Connection $conn, string $message)
    {
        if ($this->textHandler) {
            return ($this->textHandler)($conn, $message);
        }
    }

    public function handleBinaryMessage(callable $handler)
    {
        $this->binaryHandler = $handler;
    }

    public function onBinaryMessage(Connection $conn, ReadableStream $message)
    {
        if ($this->binaryHandler) {
            return ($this->binaryHandler)($conn, $message);
        }
    }
}
