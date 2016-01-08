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
    
    public function send(HttpRequest $request, float $timeout = 5): \Generator
    {
        $uri = $request->getUri();
        $secure = ($uri->getScheme() === 'https');
        $host = $uri->getHost();
        $port = $uri->getPort() ?: ($secure ? 443 : 80);
        
        $context = [];
        if (SocketStream::isAlpnSupported()) {
            $context['ssl']['alpn_protocols'] = 'h2';
        }
        
        $socket = yield from SocketStream::connect($host, $port, 'tcp', $timeout, $context);
        
        if ($secure) {
            yield from $socket->encrypt(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        }
        
        $conn = yield from Connection::connectClient($socket, $this->logger);
        
        $this->tasks[] = yield runTask($this->handleConnectionFrames($conn), sprintf('HTTP/2 Frame Handler: "%s:%u"', $host, $port));
        
        $stream = yield from $conn->openStream();
        
        return yield from $this->createResponse(yield from $stream->sendRequest($request));
    }
    
    protected function handleConnectionFrames(Connection $conn): \Generator
    {
        while (true) {
            if (false === yield from $conn->handleNextFrame()) {
                break;
            }
        }
    }

    protected function createResponse(MessageReceivedEvent $event): \Generator
    {
        yield;
        
        $response = new HttpResponse($event->getHeaderValue(':status'), $event->body);
        $response = $response->withProtocolVersion('2.0');
        
        foreach ($event->headers as $header) {
            $response = $response->withAddedHeader($header[0], $header[1]);
        }
        
        return $response;
    }
}
