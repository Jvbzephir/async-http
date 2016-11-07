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

use KoolKode\Async\Awaitable;

/**
 * Contract for a connector that can be registered with an HTTP client.
 * 
 * @author Martin Schröder
 */
interface HttpConnector
{
    /**
     * Get ALPN protocols supported by the connector.
     * 
     * @return array
     */
    public function getProtocols(): array;
    
    /**
     * Check if the connector can handle the HTTP request.
     * 
     * @param HttpRequest $request
     * @return bool
     */
    public function isRequestSupported(HttpRequest $request): bool;
    
    /**
     * Check if the negotiated ALPN protocol is supported by the connector.
     * 
     * @param string $protocol Negotiated ALPN protocol name.
     * @param array $meta Socket meta data.
     * @return bool
     */
    public function isSupported(string $protocol, array $meta = []): bool;
    
    /**
     * Shutdown connector and all associated resources.
     */
    public function shutdown(): Awaitable;
    
    /**
     * Create or reuse an HTTP connector context.
     * 
     * @param Uri $uri
     * @return HttpConnectorContext
     */
    public function getConnectorContext(Uri $uri): Awaitable;
    
    /**
     * Send the given request using a connection specified by the given context.
     * 
     * @param HttpConnectorContext $context
     * @param HttpRequest $request
     * @return HttpResponse
     */
    public function send(HttpConnectorContext $context, HttpRequest $request): Awaitable;
}
