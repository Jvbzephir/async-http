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
use KoolKode\Async\Socket\SocketStream;

/**
 * Contract for an HTTP server driver to be used with an HTTP endpoint.
 * 
 * @author Martin Schröder
 */
interface HttpDriver
{
    /**
     * Get ALPN protocols supported by the driver.
     * 
     * @return array
     */
    public function getProtocols(): array;
    
    /**
     * Handle HTTP request(s) coming in on the given stream.
     * 
     * @param SocketStream $stream
     * @param callable $action
     */
    public function handleConnection(SocketStream $stream, callable $action, string $peerName): Awaitable;
}
