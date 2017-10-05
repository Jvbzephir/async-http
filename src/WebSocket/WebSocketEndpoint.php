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

use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Context;

/**
 * Base class for implementing a WebSocket endpoint.
 *
 * Implementing things like broadcast messages requires you to keep track of all client connections yourself.
 *
 * All endpoint methods may be implemented as generators or return promises that are awaited before further messages are handled.
 *
 * @author Martin Schröder
 */
abstract class WebSocketEndpoint
{
    /**
     * Negotiate an application protocol to be used.
     *
     * @param array $protocols All acceptable protocols as advertised by the client.
     */
    public function negotiateProtocol(array $protocols): string
    {
        return '';
    }
    
    /**
     * Process the HTTP upgrade / handshake response before it is sent to the client.
     *
     * @param HttpRequest $request The HTTP request that triggered the handshake.
     * @param HttpResponse $response The prepared HTTP upgrade response.
     * @return HttpResponse Modified HTTP response to be sent.
     */
    public function onHandshake(HttpRequest $request, HttpResponse $response): HttpResponse
    {
        return $response;
    }
    
    /**
     * WebSocket client connection has been established.
     *
     * @param Connection $conn
     */
    public function onOpen(Connection $conn) { }
    
    /**
     * A client has disconnected.
     *
     * @param Connection $conn
     */
    public function onClose(Connection $conn) { }
    
    /**
     * An error occured while processing data from the given client.
     *
     * @param Connection $conn
     * @param \Throwable $e
     */
    public function onError(Connection $conn, \Throwable $e) { }
    
    /**
     * Received a text message from the client.
     *
     * @param Context $context
     * @param Connection $conn
     * @param string $message
     */
    public function onTextMessage(Context $context, Connection $conn, string $message) { }
    
    /**
     * Received a binary message from the client.
     *
     * @param Context $context
     * @param Connection $conn
     * @param ReadableStream $message
     */
    public function onBinaryMessage(Context $context, Connection $conn, ReadableStream $message) { }
}
