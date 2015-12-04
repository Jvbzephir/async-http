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

use KoolKode\Async\Stream\DuplexStreamInterface;

/**
 * Contract for HTTP endpoint server drivers.
 * 
 * @author Martin Schröder
 */
interface HttpDriverInterface
{
    /**
     * Get ALPN protocol names supported by the driver.
     * 
     * @return array
     */
    public function getProtocols(): array;
    
    /**
     * Get SSL stream context options required by the driver.
     * 
     * @return array
     */
    public function getSslOptions(): array;
    
    /**
     * Coroutine that will be run as task in order to handle a single client connection.
     * 
     * @param HttpEndpoint $endpoint
     * @param DuplexStreamInterface $socket Client connection.
     * @param callable $action
     * @return Generator
     */
    public function handleConnection(HttpEndpoint $endpoint, DuplexStreamInterface $socket, callable $action): \Generator;
}
