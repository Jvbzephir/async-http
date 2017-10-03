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
 * Contract for an HTTP server driver.
 * 
 * @author Martin Schröder
 */
interface HttpDriver
{
    /**
     * Get priority of the HTTP driver (drivers with higher priority are preferred over drivers with lower priority).
     *
     * @return int
     */
    public function getPriority(): int;
    
    /**
     * Get ALPN protocols supported by the driver.
     *
     * @return array
     */
    public function getProtocols(): array;
    
    /**
     * Check if the given ALPN protocol is supported by the driver.
     */
    public function isSupported(string $protocol): bool;
    
    /**
     * Handle all HTTP requests coming in via the given stream.
     * 
     * @param Context $context Async execution context.
     * @param DuplexStream $stream Stream being used to communicate with a client.
     * @param callable $action The action handler to be invoked for each HTTP request.
     */
    public function listen(Context $context, DuplexStream $stream, callable $action): Promise;
}
