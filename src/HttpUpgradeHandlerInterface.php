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
use KoolKode\Async\Stream\InputStreamInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
     * @param string $protocol Protocol as specified by the HTTP upgrade header.
     * @param ServerRequestInterface $request Upgrade HTTP request.
     */
    public function isUpgradeSupported(string $protocol, ServerRequestInterface $request): bool;
    
    /**
     * Check if the connection can be upgraded based on data sent by client.
     * 
     * @param HttpEndpoint $endpoint
     * @param InputStreamInterface $stream
     * @return bool
     */
    public function isDirectUpgradeSupported(HttpEndpoint $endpoint, InputStreamInterface $stream): \Generator;

    /**
     * Take control of the given connection and handle it according to the upgraded protocol.
     * 
     * @param ServerRequestInterface $request Upgrade HTTP request.
     * @param ResponseInterface $response HTTP response that can be modified and returned in order to be sent as HTTP/1.1 response.
     * @param HttpEndpoint $endpoint
     * @param DuplexStreamInterface $socket
     * @param callable $action
     * @return Generator
     */
    public function upgradeConnection(ServerRequestInterface $request, ResponseInterface $response, HttpEndpoint $endpoint, DuplexStreamInterface $socket, callable $action): \Generator;
    
    /**
     * Direct connection upgrade.
     * 
     * @param HttpEndpoint $endpoint
     * @param DuplexStreamInterface $socket
     * @param callable $action
     * @return Generator
     */
    public function upgradeDirectConnection(HttpEndpoint $endpoint, DuplexStreamInterface $socket, callable $action): \Generator;
}
