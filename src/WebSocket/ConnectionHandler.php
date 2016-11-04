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
use Psr\Log\LoggerInterface;

/**
 * Upgrades an HTTP/1.1+ connection to the WebSocket protocol.
 * 
 * @author Martin Schröder
 */
class ConnectionHandler implements UpgradeResultHandler
{
    /**
     * WebSocket GUID needed during handshake.
     * 
     * @var string
     */
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * PSR logger instance.
     * 
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Create a new WebSocket HTTP/1 upgrade handler.
     * 
     * @param LoggerInterface $logger
     */
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
        
        $compression = $this->getCompressionSettings($request);
        
        if (!empty($compression)) {
            $ext = 'permessage-deflate';
            
            foreach ($compression as $k => $v) {
                if ($v === true) {
                    $ext .= '; ' . $k;
                } elseif ($v !== false) {
                    $ext .= '; ' . $k . '=' . $v;
                }
            }
            
            $response = $response->withAddedHeader('Sec-WebSocket-Extensions', $ext);
        }
        
        return $endpoint->onHandshake($request, $response);
    }
    
    protected function getCompressionSettings(HttpRequest $request): array
    {
        static $zlib;
        static $defaults = [
            'client_no_context_takeover' => false,
            'server_no_context_takeover' => false,
            'client_max_window_bits' => 14,
            'server_max_window_bits' => 14
        ];
        
        $settings = null;
        
        if ($zlib ?? ($zlib = \function_exists('inflate_init'))) {
            foreach ($request->getHeaderTokenValues('Sec-WebSocket-Extensions') as $ext) {
                $tokens = \array_map('trim', \explode(';', $ext));
                $ext = \array_shift($tokens);
                
                if ($ext === 'permessage-deflate') {
                    $settings = $tokens;
                    
                    break;
                }
            }
        }
        
        if ($settings === null) {
            return [];
        }
        
        $parsed = $defaults;
        
        foreach ($settings as $val) {
            $parts = \array_map('trim', \explode('=', $val, 2));
            
            switch ($parts[0]) {
                case 'client_no_context_takeover':
                case 'server_no_context_takeover':
                    $parsed[$parts[0]] = true;
                    break;
                case 'client_max_window_bits':
                case 'server_max_window_bits':
                    $bits = (int) $parts[0];
                    
                    if ($bits < 8 || $bits > 15) {
                        return;
                    }
                    
                    $settings[$parts[0]] = \min(14, $bits);
                    break;
                default:
                    return;
            }
        }
        
        return $parsed;
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
        
        $conn = new Connection($socket, false, $response->getHeaderLine('Sec-WebSocket-Protocol'), $this->logger);
        
        $compression = $this->getCompressionSettings($request);
        
        if (!empty($compression)) {
            $conn->enablePerMessageDeflate($compression);
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
            
            while (null !== ($message = yield $conn->readNextMessage())) {
                if ($message instanceof ReadableStream) {
                    try {
                        yield from $this->invokeAsync($endpoint->onBinaryMessage($conn, $message));
                    } finally {
                        $message->close();
                    }
                } elseif (\is_string($message)) {
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
        
        $versions = $request->getHeaderTokenValues('Sec-Websocket-Version');
        
        if (!\in_array('13', $versions, true)) {
            throw new StatusException(Http::BAD_REQUEST, 'Secure websocket version 13 required', [
                'Sec-Websocket-Version' => '13'
            ]);
        }
    }
}
