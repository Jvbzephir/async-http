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

use KoolKode\Async\Http\HttpConnectorInterface;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\SocketStream;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\runTask;

class Http2Connector implements HttpConnectorInterface
{
    protected $logger;
    
    protected $tasks = [];
    
    public function __construct(LoggerInterface $logger = NULL)
    {
        $this->logger = $logger;
    }
    
    /**
     * {@inheritdoc}
     */
    public function shutdown()
    {
        try {
            foreach ($this->tasks as list (, $task)) {
                $task->cancel();
            }
        } finally {
            $this->tasks = [];
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function getProtocols(): array
    {
        return [
            'h2'
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function getRequestContext(HttpRequest $request): array
    {
        $uri = $request->getUri();
        $secure = ($uri->getScheme() === 'https');
        
        $host = $uri->getHost();
        $port = $uri->getPort() ?: ($secure ? 443 : 80);
        $key = sprintf('%s://%s:%u', $secure ? 'https' : 'http', $host, $port);
        
        if (isset($this->tasks[$key])) {
            return [
                'conn' => $this->tasks[$key][0]
            ];
        }
        
        return [];
    }
    
    /**
     * {@inheritdoc}
     */
    public function send(HttpRequest $request, array $context = []): \Generator
    {
        $uri = $request->getUri();
        $secure = ($uri->getScheme() === 'https');
        
        $host = $uri->getHost();
        $port = $uri->getPort() ?: ($secure ? 443 : 80);
        $key = sprintf('%s://%s:%u', $secure ? 'https' : 'http', $host, $port);
        
        if (isset($context['socket']) && $context['socket'] instanceof SocketStream) {
            $conn = yield from Connection::connectClient($context['socket'], $this->logger);
            
            $handler = yield runTask($this->handleConnectionFrames($conn), sprintf('HTTP/2 Frame Handler: "%s:%u"', $host, $port));
            $handler->setAutoShutdown(true);
            
            $this->tasks[$key] = [
                $conn,
                $handler
            ];
        } elseif (isset($context['conn']) && $context['conn'] instanceof Connection) {
            $conn = $context['conn'];
        } else {
            $options = [];
            if (SocketStream::isAlpnSupported()) {
                $options['ssl']['alpn_protocols'] = 'h2';
            }
            
            $socket = yield from SocketStream::connect($host, $port, 'tcp', 5, $options);
            
            try {
                if ($secure) {
                    yield from $socket->encrypt();
                }
            } catch (\Throwable $e) {
                $socket->close();
                
                throw $e;
            }
            
            $conn = yield from Connection::connectClient($socket, $this->logger);
            
            $handler = yield runTask($this->handleConnectionFrames($conn), sprintf('HTTP/2 Frame Handler: "%s:%u"', $host, $port));
            $handler->setAutoShutdown(true);
            
            $this->tasks[$key] = [
                $conn,
                $handler
            ];
        }
        
        $stream = yield from $conn->openStream();
        
        return $this->createResponse(yield from $stream->sendRequest($request));
    }
    
    protected function handleConnectionFrames(Connection $conn): \Generator
    {
        while (true) {
            if (false === yield from $conn->handleNextFrame()) {
                break;
            }
        }
    }

    protected function createResponse(MessageReceivedEvent $event): HttpResponse
    {
        $response = new HttpResponse($event->getHeaderValue(':status'), $event->body);
        $response = $response->withProtocolVersion('2.0');
        
        foreach ($event->headers as $header) {
            $response = $response->withAddedHeader($header[0], $header[1]);
        }
        
        return $response;
    }
}
