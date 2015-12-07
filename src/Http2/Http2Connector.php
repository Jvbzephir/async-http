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

use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\SocketStream;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\noop;
use function KoolKode\Async\runTask;

class Http2Connector
{
    protected $logger;
    
    protected $tasks = [];
    
    public function __construct(LoggerInterface $logger = NULL)
    {
        $this->logger = $logger;
    }
    
    public function shutdown()
    {
        try {
            foreach ($this->tasks as $task) {
                $task->cancel();
            }
        } finally {
            $this->tasks = [];
        }
    }
    
    public function send(HttpRequest $request): \Generator
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
        
        $conn = yield from Connection::connectClient($socket, $this->logger);
        
        $this->tasks[] = yield runTask(call_user_func(function () use ($conn) {
            while (true) {
                if (false === yield from $conn->handleNextFrame()) {
                    break;
                }
            }
        }), sprintf('HTTP/2 Frame Handler: "%s:%u"', $host, $port));
        
        $stream = yield from $conn->openStream();
        
        return yield from $this->createResponse(yield from $stream->sendRequest($request));
    }

    protected function createResponse(MessageReceivedEvent $event): \Generator
    {
        yield noop();
        
        $response = new HttpResponse();
        $response = $response->withProtocolVersion('2.0');
        $response = $response->withStatus($event->getHeaderValue(':status'));
        
        foreach ($event->headers as $header) {
            $response = $response->withAddedHeader($header[0], $header[1]);
        }
        
        $response = $response->withBody($event->body);
        
        return $response;
    }
}
