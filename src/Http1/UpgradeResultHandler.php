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
 * Contract for handlers that can upgrade an HTTP/1 connection to another protocol.
 *
 * @author Martin Schröder
 */
interface UpgradeResultHandler
{
    /**
     * Check if the given result can be used to upgrade the HTTP connection to the given protocol.
     *
     * @param string $protocol
     * @param HttpRequest $request
     * @param mixed $result
     * @return bool
     */
    public function isUpgradeSupported(string $protocol, HttpRequest $request, $result): bool;
    
    /**
     * Create an HTTP/1 upgrade response that will be sent before switching protocol.
     *
     * Passing data to the connection upgrade handling can make use of HTTP response attributes.
     *
     * @param HttpRequest $request
     * @param mixed $result
     * @return HttpResponse
     */
    public function createUpgradeResponse(HttpRequest $request, $result): HttpResponse;
    
    /**
     * Take control of the given connection upgrading it to a different protocol.
     *
     * @param Context $context Async execution context.
     * @param DuplexStream $stream The stream to be ugraded.
     * @param HttpRequest $request HTTP request that triggered the connection upgrade.
     * @param HttpResponse $response HTTP response that has been sent in order to upgrade the connection.
     */
    public function upgradeConnection(Context $context, DuplexStream $stream, HttpRequest $request, HttpResponse $response): \Generator;
}
