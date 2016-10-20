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

use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Socket\SocketStream;

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
     * Take control of the given connection.
     * 
     * @param SocketStream $socket The underlying socket connection to be used.
     * @param HttpRequest $request HTTP request that triggered the connection upgrade.
     * @param mixed $result
     */
    public function upgradeConnection(SocketStream $socket, HttpRequest $request, $result): \Generator;
}
