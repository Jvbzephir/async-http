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

use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Socket\SocketStream;

/**
 * Contract for handlers that can upgrade an HTTP/1 connection to another protocol.
 * 
 * @author Martin Schröder
 */
interface UpgradeHandler
{
    /**
     * Check if the given HTTP request is suitable for an upgrade.
     * 
     * @param string $protocol The (lowercased) protocol specified by the HTTP Upgrade header.
     * @param HttpRequest $request The request that could be upgraded.
     * @return bool True when upgrade is available.
     */
    public function isUpgradeSupported(string $protocol, HttpRequest $request): bool;
    
    /**
     * Take control of the given connection.
     *
     * @param HttpDriverContext $context HTTP driver context.
     * @param SocketStream $socket The underlying socket connection to be used.
     * @param HttpRequest $request HTTP request that triggered the connection upgrade.
     * @param callable $action Server action handler.
     */
    public function upgradeConnection(HttpDriverContext $context, SocketStream $socket, HttpRequest $request, callable $action): \Generator;
}
