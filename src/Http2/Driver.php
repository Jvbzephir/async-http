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

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpDriver;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\DuplexStream;
use Psr\Log\LoggerInterface;

/**
 * Implements the HTTP/2 protocol on the server side.
 *
 * @author Martin Schröder
 */
class Driver implements HttpDriver
{
    protected $hpackContext;
    
    protected $logger;
    
    protected $connections;
    
    public function __construct(HPackContext $hpackContext = null, LoggerInterface $logger = null)
    {
        $this->hpackContext = $hpackContext ?? HPackContext::createServerContext();
        $this->logger = $logger;
        
        $this->connections = new \SplObjectStorage();
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
    public function handleConnection(DuplexStream $stream, callable $action): Awaitable
    {
        return new Coroutine(function () use ($stream, $action) {
            $conn = yield Connection::connectServer($stream, new HPack($this->hpackContext), $this->logger);
            
            try {
                while (null !== ($received = yield $conn->nextRequest())) {
                    new Coroutine($this->processRequest($conn, $action, ...$received), true);
                }
            } finally {
                yield $conn->shutdown();
            }
        });
    }

    protected function processRequest(Connection $conn, callable $action, Stream $stream, HttpRequest $request): \Generator
    {
        if ($this->logger) {
            $this->logger->info(sprintf('%s %s HTTP/%s', $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion()));
        }
        
        $response = $action($request);
        
        if ($response instanceof \Generator) {
            $response = yield from $response;
        }
        
        if (!$response instanceof HttpResponse) {
            if ($this->logger) {
                $type = \is_object($response) ? \get_class($response) : \gettype($response);
            
                $this->logger->error(\sprintf('Expecting HTTP response, server action returned %s', $type));
            }
            
            $response = new HttpResponse(Http::INTERNAL_SERVER_ERROR);
        }
        
        $response = $response->withProtocolVersion($request->getProtocolVersion());
        $response = $response->withHeader('Server', 'KoolKode HTTP Server');
        
        if ($this->logger) {
            $reason = rtrim(' ' . $response->getReasonPhrase());
            
            if ($reason === '') {
                $reason = rtrim(' ' . Http::getReason($response->getStatusCode()));
            }
            
            $this->logger->info(sprintf('HTTP/%s %03u%s', $response->getProtocolVersion(), $response->getStatusCode(), $reason));
        }
        
        yield $stream->sendResponse($request, $response);
    }
}
