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

namespace KoolKode\Async\Http;

use KoolKode\Async\Context;
use KoolKode\Async\Promise;
use KoolKode\Async\Stream\DuplexStream;

/**
 * Contract for an HTTP client connector.
 * 
 * @author Martin Schröder
 */
interface HttpConnector
{
    /**
     * Get the connector priority (should be set to the supported HTTP version).
     */
    public function getPriority(): int;
    
    /**
     * Check if the connector can send the given HTTP request.
     */
    public function isRequestSupported(HttpRequest $request): bool;
    
    /**
     * Check if the connector has a re-usable connection to the given host.
     */
    public function isConnected(Context $context, string $key): Promise;
    
    /**
     * Get ALPN protocols supported by the connector.
     */
    public function getProtocols(): array;
    
    /**
     * Check if the given ALPN protocol is supported by the connector.
     */
    public function isSupported(string $protocol): bool;
    
    /**
     * Send the given HTTP request to the server and return a response.
     * 
     * @param Context $context Async execution context.
     * @param HttpRequest $request HTTP request to be sent.
     * @param DuplexStream $stream (Optional) new connection to be used.
     * @return HttpResponse HTTP response received from the server.
     */
    public function send(Context $context, HttpRequest $request, ?DuplexStream $stream = null): Promise;
}
