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
use KoolKode\Async\Promise;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpClient;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http1\Upgrade;

/**
 * WebSocket client that can be used to establish connections to remote WebSocket endpoints.
 *
 * @author Martin Schröder
 */
class WebSocketClient
{
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
    public function __construct(HttpClient $httpClient, bool $deflateSupported = false)
    {
        $this->httpClient = $httpClient;
        $this->deflateSupported = $deflateSupported;
    }
    
    /**
     * Connect to the given WebSocket endpoint.
     *
     * @param string $uri WebSocket ws(s):// or http(s):// URL.
     * @param array $protocols Optional application protocols to be advertised to the remote endpoint.
     * @return Connection
     */
    public function connect(Context $context, string $uri, array $protocols = []): Promise
    {
        return $context->task($this->connectTask($context, $uri, $protocols));
    }
    
    /**
     * Coroutine that performs the WebSocket handshake and upgrades the socket connection to a WebSocket in client mode.
     */
    protected function connectTask(Context $context, string $uri, array $protocols): \Generator
    {
        $location = $uri;
        $m = null;
        
        if (\preg_match("'^(wss?)://.+$'i", $uri, $m)) {
            $uri = ((\strtolower($m[1]) === 'ws') ? 'http' : 'https') . \substr($m[0], \strlen($m[1]));
        }
        
        $nonce = \base64_encode(\random_bytes(16));
        $request = $this->createHandshakeRequest($uri, $nonce, $protocols);
        
        $response = yield $this->httpClient->send($context, $request);
        $upgrade = $response->getAttribute(Upgrade::class);
        
        if ($response->getStatusCode() != Http::SWITCHING_PROTOCOLS || !$upgrade instanceof Upgrade) {
            throw new \RuntimeException('Missing HTTP upgrade in response with status ' . $response->getStatusCode());
        }
        
        try {
            if (!\in_array('websocket', $upgrade->protocols, true)) {
                throw new \RuntimeException(\sprintf('HTTP upgrade header did not specify websocket: "%s"', $response->getHeaderLine('Upgrade')));
            }
            
            if (\base64_encode(\sha1($nonce . Connection::GUID, true)) !== $response->getHeaderLine('Sec-WebSocket-Accept')) {
                throw new \RuntimeException('Failed to verify Sec-WebSocket-Accept HTTP header');
            }
            
            if ($response->hasHeader('Sec-WebSocket-Protocol')) {
                if (!\in_array($response->getHeaderLine('Sec-WebSocket-Protocol'), $protocols, true)) {
                    throw new \OutOfRangeException(\sprintf('Unsupported protocol: "%s"', $response->getHeaderLine('Sec-WebSocket-Protocol')));
                }
            }
            
            $conn = new Connection($context, true, $upgrade->stream, $response->getHeaderLine('Sec-WebSocket-Protocol'));
            
            if ($deflate = $this->negotiatePerMessageDeflate($response)) {
                if (!$this->deflateSupported) {
                    throw new \RuntimeException('Server enabled permessage-deflate but client does not support the extension');
                }
                
                $conn->enablePerMessageDeflate($deflate);
            }
        } catch (\Throwable $e) {
            $upgrade->stream->close($e);
            
            throw $e;
        }
        
        return $conn;
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
        ], null, '1.1');
        
        if (!empty($protocols)) {
            $request = $request->withHeader('Sec-WebSocket-Protocol', \implode(', ', $protocols));
        }
        
        if ($this->deflateSupported && ($zlib ?? ($zlib = \function_exists('inflate_init')))) {
            $request = $request->withAddedHeader('Sec-WebSocket-Extensions', 'permessage-deflate');
        }
        
        return $request;
    }
    
    /**
     * Negotiate permessage-deflate settings with the server using the given handshake HTTP response.
     *
     * @param HttpResponse $response
     * @return PerMessageDeflate Or null when the server did not enable the extension.
     */
    protected function negotiatePerMessageDeflate(HttpResponse $response): ?PerMessageDeflate
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
            return null;
        }
        
        try {
            return PerMessageDeflate::fromHeaderToken($extension);
        } catch (\OutOfRangeException $e) {
            return null;
        }
    }
}
