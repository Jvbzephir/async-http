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
     *
     * @return array
     */
    public function getProtocols(): array;
    
    /**
     * Send the given HTTP request.
     * 
     * @param HttpRequest $request
     * @return HttpResponse
     */
    public function send(HttpRequest $request): \Generator;
}
