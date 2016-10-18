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
use KoolKode\Async\Http\StringBody;
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
    public function handleConnection(DuplexStream $stream): Awaitable
    {
        return new Coroutine(function () use ($stream) {
            $conn = yield Connection::connectServer($stream, new HPack($this->hpackContext), $this->logger);
            
            try {
                while (null !== ($received = yield $conn->nextRequest())) {
                    new Coroutine($this->processRequest($conn, ...$received), true);
                }
            } finally {
                yield $conn->shutdown();
            }
        });
    }

    protected function processRequest(Connection $conn, Stream $stream, HttpRequest $request): \Generator
    {
        if ($this->logger) {
            $this->logger->info(sprintf('%s %s HTTP/%s', $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion()));
        }
        
        $response = new HttpResponse();
        $response = $response->withProtocolVersion($request->getProtocolVersion());
        
        $response = $response->withHeader('Content-Type', 'application/json');
        $response = $response->withHeader('Server', 'KoolKode HTTP Server');
        
        $response = $response->withBody(new StringBody(json_encode([
            'message' => 'Hello HTTP/2 client :)',
            'time' => (new \DateTime())->format(\DateTime::ISO8601),
            'bootstrap' => str_replace('\\', '/', get_included_files()[0])
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)));
        
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
