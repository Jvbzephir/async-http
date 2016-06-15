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

/**
 * Contract for an HTTP connector.
 * 
 * @author Martin Schröder
 */
interface HttpConnectorInterface
{
    /**
     * Shut down all pending connections.
     */
    public function shutdown();
 
    /**
     * Get ALPN protocol names supported by the driver.
     */
    public function getProtocols(): array;
    
    /**
     * Get the HTTP context being used by the connector.
     */
    public function getHttpContext(): HttpContext;
    
    /**
     * Get a connector context that can be used to re-use resources.
     * 
     * @param HttpRequest $request
     * @return HttpConnectorContext or NULL.
     */
    public function getConnectorContext(HttpRequest $request);
    
    /**
     * Send the given HTTP request.
     * 
     * @param HttpRequest $request
     * @param HttpConnectorContext $context
     * @return HttpResponse
     */
    public function send(HttpRequest $request, HttpConnectorContext $context = NULL): \Generator;
}
