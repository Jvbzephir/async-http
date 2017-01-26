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
use KoolKode\Async\Http\Logger;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * WebSocket client that can be used to establish connections to remote WebSocket endpoints.
 * 
 * @author Martin Schröder
 */
class Client implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    /**
     * WebSocket GUID needed during handshake.
     *
     * @var string
     */
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    /**
     * Enable permessage-deflate WebSocket extension?
     * 
     * @var string
     */
    protected $deflateSupported = false;
    
    /**
     * HTTP client being used to perform the initial handshake.
     * 
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * Cretae a new WebSocket client.
     * 
     * @param HttpClient $httpClient HTTP client to be used for handshake request (requires HTTP/1 connector).
     */
    public function __construct(HttpClient $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->logger = new Logger(static::class);
    }

    /**
     * Enable / disable permessage-deflate WebSocket extension.
     */
    public function setDeflateSupported(bool $deflate)
    {
        $this->deflateSupported = $deflate;
    }

    /**
     * Connect to the given WebSocket endpoint.
     * 
     * @param string $uri WebSocket ws(s):// or http(s):// URL.
     * @param array $protocols Optional application protocols to be advertised to the remote endpoint.
     * @return Connection
     */
    public function connect(string $uri, array $protocols = []): Awaitable
    {
        return new Coroutine($this->handshake($uri, $protocols));
    }

    /**
     * Coroutine that performs the WebSocket handshake and upgrades the socket connection to a WebSocket in client mode.
     */
    protected function handshake(string $uri, array $protocols): \Generator
    {
        $location = $uri;
        $m = null;
        
        if (\preg_match("'^(wss?)://.+$'i", $uri, $m)) {
            $uri = ((\strtolower($m[1]) === 'ws') ? 'http' : 'https') . \substr($m[0], \strlen($m[1]));
        }
        
        $nonce = \base64_encode(\random_bytes(16));
        $request = $this->createHandshakeRequest($uri, $nonce, $protocols);
        
        $response = yield $this->httpClient->send($request);
        
        $this->assertHandshakeSucceeded($response, $nonce, $protocols);
        
        // Discard HTTP body contents but do not close the underlying socket stream.
        $stream = yield $response->getBody()->getReadableStream();
        
        while (null !== yield $stream->read());
        
        return $this->establishConnection($location, $response);
    }

    /**
     * Establish a WebSocket connection using the given handshake HTTP response.
     * 
     * @param string $location
     * @param HttpResponse $response
     * @return Connection
     */
    protected function establishConnection(string $location, HttpResponse $response): Connection
    {
        $socket = $response->getAttribute(SocketStream::class);
        
        if (!$socket instanceof SocketStream) {
            throw new \RuntimeException('Failed to access HTTP socket stream via response attribute');
        }
        
        try {
            $conn = new Connection($socket, true, $response->getHeaderLine('Sec-WebSocket-Protocol'));
            
            $this->logger->debug('Established WebSocket connection to {peer} ({uri})', [
                'peer' => $socket->getRemoteAddress(),
                'uri' => $location
            ]);
            
            if ($deflate = $this->negotiatePerMessageDeflate($response)) {
                if (!$this->deflateSupported) {
                    throw new \RuntimeException('Server enabled permessage-deflate but client does not support the extension');
                }
                
                $conn->enablePerMessageDeflate($deflate);
            }
            
            return $conn;
        } catch (\Throwable $e) {
            $socket->close();
            
            throw $e;
        }
    }

    /**
     * Create an HTTP/1.1 request that initiates the WebSocket handshake.
     * 
     * @param string $uri URI using http(s) scheme.
     * @param string $nonce Random nonce to be used to confirm the handshake response.
     * @param array $protocols Application protocols supported by the client.
     * @return HttpRequest Handshek request.
     */
    protected function createHandshakeRequest(string $uri, string $nonce, array $protocols): HttpRequest
    {
        static $zlib;
        
        $request = new HttpRequest($uri, Http::GET, [
            'Connection' => 'upgrade',
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => $nonce
        ], '1.1');
        
        if (!empty($protocols)) {
            $request = $request->withHeader('Sec-WebSocket-Protocol', \implode(', ', $protocols));
        }
        
        if ($this->deflateSupported && ($zlib ?? ($zlib = \function_exists('inflate_init')))) {
            $request = $request->withAddedHeader('Sec-WebSocket-Extensions', 'permessage-deflate');
        }
        
        return $request;
    }

    /**
     * Assert that the given response indicates a succeeded WebSocket handshake.
     */
    protected function assertHandshakeSucceeded(HttpResponse $response, string $nonce, array $protocols)
    {
        if ($response->getStatusCode() !== Http::SWITCHING_PROTOCOLS) {
            throw new \RuntimeException(\sprintf('Unexpected HTTP response code: %s', $response->getStatusCode()));
        }
        
        if (!\in_array('upgrade', $response->getHeaderTokenValues('Connection'), true)) {
            throw new \RuntimeException(\sprintf('HTTP connection header did not contain upgrade: "%s"', $response->getHeaderLine('Connection')));
        }
        
        if ('websocket' !== \strtolower($response->getHeaderLine('Upgrade'))) {
            throw new \RuntimeException(\sprintf('HTTP upgrade header did not specify websocket: "%s"', $response->getHeaderLine('Upgrade')));
        }
        
        if (\base64_encode(\sha1($nonce . self::GUID, true)) !== $response->getHeaderLine('Sec-WebSocket-Accept')) {
            throw new \RuntimeException('Failed to verify Sec-WebSocket-Accept HTTP header');
        }
        
        if ($response->hasHeader('Sec-WebSocket-Protocol')) {
            if (!\in_array($response->getHeaderLine('Sec-WebSocket-Protocol'), $protocols, true)) {
                throw new \OutOfRangeException(\sprintf('Unsupported protocol: "%s"', $response->getHeaderLine('Sec-WebSocket-Protocol')));
            }
        }
    }

    /**
     * Negotiate permessage-deflate settings with the server using the given handshake HTTP response.
     * 
     * @param HttpResponse $response
     * @return PerMessageDeflate Or null when the server did not enable the extension.
     */
    protected function negotiatePerMessageDeflate(HttpResponse $response)
    {
        static $zlib;
        
        $extension = null;
        
        if ($zlib ?? ($zlib = \function_exists('inflate_init'))) {
            foreach ($response->getHeaderTokens('Sec-WebSocket-Extensions') as $ext) {
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
}
