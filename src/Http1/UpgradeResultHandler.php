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

use KoolKode\Async\Context;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\DuplexStream;

/**
 * Contract for handlers that can upgrade an HTTP/1 connection to another protocol based on a value returned from an HTTP handler.
 *
 * @author Martin Schröder
 */
interface UpgradeResultHandler
{
    /**
     * Check if the given result can be used to upgrade the HTTP connection to the given protocol.
     *
     * @param string $protocol Name of the protocol the client wishes to enable.
     * @param HttpRequest $request HTTP request being sent by the client.
     * @param mixed $result Result returned by an HTTP handler.
     * @return bool Return true if the connection can be upgraded by this handler.
     */
    public function isUpgradeSupported(string $protocol, HttpRequest $request, $result): bool;

    /**
     * Create an HTTP/1 upgrade response that will be sent before switching protocol.
     *
     * Passing data to the connection upgrade method shoud use HTTP response attributes.
     * 
     * The driver will automatically set the status code to 101 and add an appropriate Connection header.
     *
     * @param HttpRequest $request HTTP request that triggered the connection upgrade.
     * @param mixed $result The value returned by an HTTP handler that triggered the connection upgrade.
     * @return HttpResponse An HTTP response to be sent to the client.
     */
    public function createUpgradeResponse(HttpRequest $request, $result): HttpResponse;

    /**
     * Take full control of the given connection upgrading it to a different protocol.
     *
     * @param Context $context Async execution context.
     * @param DuplexStream $stream The stream to be ugraded.
     * @param HttpRequest $request HTTP request that triggered the connection upgrade.
     * @param HttpResponse $response HTTP response that has been sent in order to upgrade the connection.
     */
    public function upgradeConnection(Context $context, DuplexStream $stream, HttpRequest $request, HttpResponse $response): \Generator;
}
