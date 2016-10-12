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
use KoolKode\Async\Http\HttpDriver;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StringBody;
use KoolKode\Async\Stream\DuplexStream;

class Driver implements HttpDriver
{
    protected $hpackContext;
    
    protected $connections;
    
    public function __construct(HPackContext $hpackContext = null)
    {
        $this->hpackContext = $hpackContext ?? new HPackContext();
        
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
            $conn = yield Connection::connectServer($stream, new HPack($this->hpackContext));
            
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
        $response = new HttpResponse();
        $response = $response->withProtocolVersion($request->getProtocolVersion());
        
        $response = $response->withHeader('Content-Type', 'application/json');
        $response = $response->withBody(new StringBody(json_encode([
            'message' => 'Hello HTTP/2 client :)',
            'time' => (new \DateTime())->format(\DateTime::ISO8601),
            'bootstrap' => str_replace('\\', '/', get_included_files()[0])
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)));
        
        yield $stream->sendResponse($request, $response);
    }
}
