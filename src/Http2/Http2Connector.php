<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Stream\SocketStream;
use KoolKode\K1\Http\HttpFactoryInterface;
use KoolKode\Stream\ResourceInputStream;
use Psr\Http\Message\RequestInterface;

use function KoolKode\Async\createTempStream;
use function KoolKode\Async\runTask;

class Http2Connector
{
    protected $httpFactory;
    
    protected $sockets = [];
    
    public function __construct(HttpFactoryInterface $factory)
    {
        $this->httpFactory = $factory;
    }

    public function send(RequestInterface $request): \Generator
    {
        $uri = $request->getUri();
        $secure = ($uri->getScheme() === 'https');
        $host = $uri->getHost();
        $port = $uri->getPort() ?: ($secure ? 443 : 80);
        
        $context = [];
        if (defined('OPENSSL_VERSION_NUMBER') && OPENSSL_VERSION_NUMBER >= 0x10002000) {
            $context['ssl']['alpn_protocols'] = 'h2';
        }
        
        $socket = yield from SocketStream::connect($host, $port, 'tcp', 0, $context);
        
        if ($secure) {
            yield from $socket->encrypt(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        }
        
        $meta = $socket->getMetadata();
        if (empty($meta['crypto']['alpn_protocol']) || $meta['crypto']['alpn_protocol'] !== 'h2') {
            throw new \RuntimeException('Failed to negotiate encrypted HTTP/2.0 connection');
        }
        
        $conn = yield from Connection::connectClient($socket);
        
        yield runTask(call_user_func(function () use($conn) {
            while (true) {
                if (false === yield from $conn->handleNextFrame()) {
                    break;
                }
            }
        }));
        
        $stream = yield from $conn->openStream();
        
        return yield from $this->createResponse(yield from $stream->sendRequest($request));
    }
    
    protected function createResponse(MessageReceivedEvent $event): \Generator
    {
        $response = $this->httpFactory->createResponse();
        $response = $response->withProtocolVersion('2.0');
        $response = $response->withStatus($event->getHeaderValue(':status'));
        
        foreach ($event->headers as $header) {
            $response = $response->withAddedHeader($header[0], $header[1]);
        }
        
        $buffer = yield createTempStream();
        
        while (!$event->body->eof()) {
            yield from $buffer->write(yield from $event->body->read());
        }
        
        $buffer = $buffer->detach();
        rewind($buffer);
        stream_set_blocking($buffer, 1);
        
        $response = $response->withBody(new ResourceInputStream($buffer));
        
        return $response;
    }
}
