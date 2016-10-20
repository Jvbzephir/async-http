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

namespace KoolKode\Async\Http\WebSocket;

use Interop\Async\Awaitable as Promise;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Http1\UpgradeResultHandler;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerInterface;

class ConnectionHandler implements UpgradeResultHandler
{
    /**
     * WebSocket GUID needed during handshake.
     * 
     * @var string
     */
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    protected $logger;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function isUpgradeSupported(string $protocol, HttpRequest $request, $result): bool
    {
        if ($protocol !== 'websocket') {
            return false;
        }
        
        return $result instanceof Endpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function upgradeConnection(SocketStream $socket, HttpRequest $request, $endpoint): \Generator
    {
        if (!$endpoint instanceof Endpoint) {
            throw new \InvalidArgumentException('No endpoint object passed to WebSocket handler');
        }
        
        $this->assertUpgradePossible($socket, $request);
        
        // Discard HTTP request body prior to connection upgrade.
        $bodyStream = yield $request->getBody()->getReadableStream();
        
        try {
            while (null !== (yield $bodyStream->read()));
        } finally {
            $bodyStream->close();
        }
        
        yield from $this->sendHandshake($socket, $request);
        
        if ($this->logger) {
            $this->logger->info(\sprintf('HTTP/%s connection upgraded to WebSocket', $request->getProtocolVersion()));
        }
        
        yield from $this->delegateToEndpoint(new Connection($socket, false), $endpoint);
    }

    protected function delegateToEndpoint(Connection $conn, Endpoint $endpoint): \Generator
    {
        try {
            yield from $this->invokeAsync($endpoint->onOpen($conn));
            
            while (null !== ($message = yield $conn->readNextMessage())) {
                if ($message instanceof TextMessage) {
                    yield from $this->invokeAsync($endpoint->onTextMessage($conn, $message));
                } elseif ($message instanceof BinaryMessage) {
                    yield from $this->invokeAsync($endpoint->onBinaryMessage($conn, $message));
                }
            }
        } catch (\Throwable $e) {
            yield from $this->invokeAsync($endpoint->onError($conn, $e));
        } finally {
            try {
                yield from $this->invokeAsync($endpoint->onClose($conn));
            } finally {
                $conn->shutdown();
            }
        }
    }

    protected function invokeAsync($result): \Generator
    {
        if ($result instanceof \Generator) {
            return yield from $result;
        }
        
        if ($result instanceof Promise) {
            return yield $result;
        }
        
        return $result;
    }

    protected function assertUpgradePossible(SocketStream $socket, HttpRequest $request)
    {
        if ($request->getMethod() !== Http::GET) {
            throw new StatusException(Http::METHOD_NOT_ALLOWED, 'WebSocket upgrade requires an HTTP GET request', [
                'Allow' => Http::GET,
                'Sec-Websocket-Version' => '13'
            ]);
        }
        
        if (!$request->hasHeader('Sec-Websocket-Key')) {
            throw new StatusException(Http::BAD_REQUEST, 'Missing Sec-Websocket-Key HTTP header', [
                'Sec-Websocket-Version' => '13'
            ]);
        }
        
        $versions = $request->getHeaderTokens('Sec-Websocket-Version');
        
        if (!\in_array('13', $versions, true)) {
            throw new StatusException(Http::BAD_REQUEST, 'Secure websocket version 13 required', [
                'Sec-Websocket-Version' => '13'
            ]);
        }
    }

    protected function sendHandshake(SocketStream $socket, HttpRequest $request): \Generator
    {
        $accept = \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . self::GUID, true));
        
        $buffer = Http::getStatusLine(Http::SWITCHING_PROTOCOLS, $request->getProtocolVersion()) . "\r\n";
        $buffer .= "Connection: upgrade\r\n";
        $buffer .= "Upgrade: websocket\r\n";
        $buffer .= "Sec-WebSocket-Accept: $accept\r\n";
        $buffer .= "Sec-WebSocket-Version: 13\r\n";
        
        return yield $socket->write($buffer . "\r\n");
    }
}
