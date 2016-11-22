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

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http1\UpgradeResultHandler;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\ReadableStream;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Upgrades an HTTP/1.1+ connection to the WebSocket protocol.
 * 
 * @author Martin Schröder
 */
class ConnectionHandler implements UpgradeResultHandler, LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    /**
     * WebSocket GUID needed during handshake.
     * 
     * @var string
     */
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Enable usage of permessage-deflate WebSocket extension?
     * 
     * @var bool
     */
    protected $deflateSupported = false;
    
    /**
     * Enable / disable usage of permessage-deflate WebSocket extension.
     */
    public function setDeflateSupported(bool $deflate)
    {
        $this->deflateSupported = $deflate;
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
    public function createUpgradeResponse(HttpRequest $request, $endpoint): HttpResponse
    {
        if (!$endpoint instanceof Endpoint) {
            throw new \InvalidArgumentException('No endpoint object passed to WebSocket handler');
        }
        
        $this->assertUpgradePossible($request);
        
        $accept = \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . self::GUID, true));
        
        $response = new HttpResponse(Http::SWITCHING_PROTOCOLS, [
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Accept' => $accept
        ]);
        
        $response = $response->withReason('WebSocket Handshake');
        $response = $response->withAttribute(Endpoint::class, $endpoint);
        
        $protocol = $endpoint->negotiateProtocol($request->getHeaderTokenValues('Sec-WebSocket-Protocol'));
        
        if ($protocol !== '') {
            $response = $response->withHeader('Sec-WebSocket-Protocol', $protocol);
        }
        
        if ($this->deflateSupported && $deflate = $this->negotiatePerMessageDeflate($request)) {
            $response = $response->withAddedHeader('Sec-WebSocket-Extensions', $deflate->getExtensionHeader());
            $response = $response->withAttribute(PerMessageDeflate::class, $deflate);
        }
        
        return $endpoint->onHandshake($request, $response);
    }

    /**
     * Negotiate permessage-deflate WebSocket extensio if supported by the clientn.
     * 
     * @param HttpRequest $request
     * @return PerMessageDeflate Or null when not supported by client / server or invalid window sizes are specified.
     */
    protected function negotiatePerMessageDeflate(HttpRequest $request)
    {
        static $zlib;
        
        $extension = null;
        
        if ($zlib ?? ($zlib = \function_exists('inflate_init'))) {
            foreach ($request->getHeaderTokens('Sec-WebSocket-Extensions') as $ext) {
                if (\strtolower($ext->getValue()) === 'permessage-deflate') {
                    $extension = $ext;
                    
                    break;
                }
            }
        }
        
        if ($extension === null) {
            return;
        }
        
        try {
            return PerMessageDeflate::fromHeaderToken($extension);
        } catch (\OutOfRangeException $e) {
            return;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function upgradeConnection(SocketStream $socket, HttpRequest $request, HttpResponse $response): \Generator
    {
        $endpoint = $response->getAttribute(Endpoint::class);
        
        if (!$endpoint instanceof Endpoint) {
            throw new \InvalidArgumentException('No endpoint object passed to WebSocket handler');
        }
        
        if ($this->logger) {
            $this->logger->debug('HTTP/{protocol} connection from {peer} upgraded to WebSocket', [
                'protocol' => $request->getProtocolVersion(),
                'peer' => $socket->getRemoteAddress()
            ]);
        }
        
        $conn = new Connection($socket, false, $response->getHeaderLine('Sec-WebSocket-Protocol'));
        
        if ($this->logger) {
            $conn->setLogger($this->logger);
        }
        
        if ($deflate = $response->getAttribute(PerMessageDeflate::class)) {
            $conn->enablePerMessageDeflate($deflate);
        }
        
        yield from $this->delegateToEndpoint($conn, $endpoint);
    }

    /**
     * Delegate connection contol to the given WebSocket endpoint.
     */
    protected function delegateToEndpoint(Connection $conn, Endpoint $endpoint): \Generator
    {
        try {
            yield from $this->invokeAsync($endpoint->onOpen($conn));
            
            while (null !== ($message = yield $conn->receive())) {
                if ($message instanceof ReadableStream) {
                    try {
                        yield from $this->invokeAsync($endpoint->onBinaryMessage($conn, $message));
                    } finally {
                        $message->close();
                    }
                } else {
                    yield from $this->invokeAsync($endpoint->onTextMessage($conn, $message));
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

    /**
     * Ensure endpoint generators / returned promises are completed before the next message is dispatched.
     */
    protected function invokeAsync($result): \Generator
    {
        if ($result instanceof \Generator) {
            return yield from $result;
        }
        
        return yield $result;
    }

    /**
     * Assert that the given HTTP request can be upgraded to the WebSocket protocol.
     */
    protected function assertUpgradePossible(HttpRequest $request)
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
        
        if (!\in_array('13', $request->getHeaderTokenValues('Sec-Websocket-Version'), true)) {
            throw new StatusException(Http::BAD_REQUEST, 'Secure websocket version 13 required', [
                'Sec-Websocket-Version' => '13'
            ]);
        }
    }
}
