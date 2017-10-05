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

use KoolKode\Async\Context;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Http\Http1\UpgradeResultHandler;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableStream;

class WebSocketServer implements UpgradeResultHandler
{
    protected $deflateSupported;
    
    public function __construct(bool $deflateSupported = false)
    {
        $this->deflateSupported = $deflateSupported;
    }
    
    /**
     * {@inheritdoc}
     */
    public function isUpgradeSupported(string $protocol, HttpRequest $request, $result): bool
    {
        if ($protocol !== 'websocket') {
            return false;
        }
        
        return $result instanceof WebSocketEndpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function createUpgradeResponse(HttpRequest $request, $endpoint): HttpResponse
    {
        if (!$endpoint instanceof WebSocketEndpoint) {
            throw new \InvalidArgumentException('No endpoint object passed to WebSocket handler');
        }
        
        $this->assertUpgradePossible($request);
        
        $accept = \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . Connection::GUID, true));
        
        $response = new HttpResponse(Http::SWITCHING_PROTOCOLS, [
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Accept' => $accept
        ]);
        
        $response = $response->withReason('WebSocket Handshake');
        $response = $response->withAttribute(WebSocketEndpoint::class, $endpoint);
        
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
     * {@inheritdoc}
     */
    public function upgradeConnection(Context $context, DuplexStream $stream, HttpRequest $request, HttpResponse $response): \Generator
    {
        $endpoint = $response->getAttribute(WebSocketEndpoint::class);
        
        if (!$endpoint instanceof WebSocketEndpoint) {
            throw new \InvalidArgumentException('No endpoint object passed to WebSocket handler');
        }
        
        $deflate = $response->getAttribute(PerMessageDeflate::class);
        
        $conn = new Connection($context, false, $stream, $response->getHeaderLine('Sec-WebSocket-Protocol'), $deflate);
        
        try {
            $endpoint->onOpen($conn);
            
            while (null !== ($message = yield $conn->receive($context))) {
                if ($message instanceof ReadableStream) {
                    $result = $endpoint->onBinaryMessage($context, $conn, $message);
                } else {
                    $result = $endpoint->onTextMessage($context, $conn, $message);
                }
                
                if ($result instanceof \Generator) {
                    yield from $result;
                }
            }
        } finally {
            $endpoint->onClose($conn);
            
            $conn->close();
        }
    }

    /**
     * Assert that the given HTTP request can be upgraded to the WebSocket protocol.
     */
    protected function assertUpgradePossible(HttpRequest $request): void
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

    /**
     * Negotiate permessage-deflate WebSocket extensio if supported by the clientn.
     *
     * @param HttpRequest $request
     * @return PerMessageDeflate Or null when not supported by client / server or invalid window sizes are specified.
     */
    protected function negotiatePerMessageDeflate(HttpRequest $request): ?PerMessageDeflate
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
            return null;
        }
        
        try {
            return PerMessageDeflate::fromHeaderToken($extension);
        } catch (\OutOfRangeException $e) {
            return null;
        }
    }
}
