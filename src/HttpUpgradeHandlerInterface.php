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

use KoolKode\Async\Stream\BufferedDuplexStreamInterface;

/**
 * Contract for a handler that can upgrade an HTTP/1.1 connection.
 * 
 * @author Martin Schröder
 */
interface HttpUpgradeHandlerInterface
{
    /**
     * Check if the handler can upgrade the connection to the given protocol.
     * 
     * Each upgrade handler will be invoked once with an empty protocol, this allows for direct upgrades based on the parsed HTTP header data.
     * 
     * @param string $protocol Protocol as specified by the HTTP upgrade header.
     * @param HttpRequest $request Upgrade HTTP request.
     */
    public function isUpgradeSupported(string $protocol, HttpRequest $request): bool;

    /**
     * Take control of the given connection and handle it according to the upgraded protocol.
     * 
     * @param BufferedDuplexStreamInterface $socket
     * @param HttpRequest $request Upgrade HTTP request.
     * @param HttpResponse $response HTTP response that can be modified and returned in order to be sent as HTTP/1.1 response.
     * @param HttpEndpoint $endpoint
     * @param callable $action
     * @return Generator
     */
    public function upgradeConnection(BufferedDuplexStreamInterface $socket, HttpRequest $request, HttpResponse $response, HttpEndpoint $endpoint, callable $action): \Generator;
}
