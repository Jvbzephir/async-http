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

use KoolKode\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpClient;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerInterface;

class Client
{
    /**
     * WebSocket GUID needed during handshake.
     *
     * @var string
     */
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    protected $httpClient;
    
    protected $logger;

    public function __construct(HttpClient $httpClient = null, LoggerInterface $logger = null)
    {
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->logger = $logger;
    }

    public function connect(string $uri, array $protocols = []): Awaitable
    {
        return new Coroutine($this->handshake($uri, $protocols));
    }

    protected function handshake(string $uri, array $protocols): \Generator
    {
        $m = null;
        
        if (\preg_match("'^(wss?)://.+$'i", $uri, $m)) {
            $uri = ((\strtolower($m[1]) === 'ws') ? 'http' : 'https') . \substr($m[0], \strlen($m[1]));
        }
        
        $nonce = \base64_encode(\random_bytes(16));
        
        $request = new HttpRequest($uri, Http::GET, [
            'Connection' => 'upgrade',
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => $nonce
        ]);
        
        if (!empty($protocols)) {
            $request = $request->withHeader('Sec-WebSocket-Protocol', \implode(', ', $protocols));
        }
        
        $response = yield $this->httpClient->send($request);
        
        $this->assertHandshakeSucceeded($response, $nonce);
        
        // Discard HTTP body contents but do not close the underlying socket stream.
        $stream = yield $response->getBody()->getReadableStream();
        
        while (null !== yield $stream->read());
        
        $socket = $response->getAttribute(SocketStream::class);
        
        if (!$socket instanceof SocketStream) {
            throw new \RuntimeException('Failed to access HTTP socket stream via response attribute');
        }
        
        if ($this->logger) {
            $this->logger->debug('Established WebSocket connection to {peer} ({uri})', [
                'peer' => $socket->getRemoteAddress(),
                'uri' => $uri
            ]);
        }
        
        return new Connection($socket, true, $response->getHeaderLine('Sec-WebSocket-Protocol'), $this->logger);
    }
    
    protected function assertHandshakeSucceeded(HttpResponse $response, string $nonce)
    {
        if ($response->getStatusCode() !== Http::SWITCHING_PROTOCOLS) {
            throw new \RuntimeException(\sprintf('Unexpected HTTP response code: %s', $response->getStatusCode()));
        }
        
        if (!\in_array('upgrade', $response->getHeaderTokens('Connection'))) {
            throw new \RuntimeException(\sprintf('HTTP connection header did not contain upgrade: "%s"', $response->getHeaderLine('Connection')));
        }
        
        if ('websocket' !== \strtolower($response->getHeaderLine('Upgrade'))) {
            throw new \RuntimeException(\sprintf('HTTP upgrade header did not specify websocket: "%s"', $response->getHeaderLine('Upgrade')));
        }
        
        if (!$response->hasHeader('Sec-Websocket-Accept')) {
            throw new \RuntimeException('Missing Sec-WebSocket-Accept HTTP header');
        }
        
        if (\base64_encode(\sha1($nonce . self::GUID, true)) !== $response->getHeaderLine('Sec-WebSocket-Accept')) {
            throw new \RuntimeException('Failed to verify Sec-WebSocket-Accept HTTP header');
        }
    }
}
