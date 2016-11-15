<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Fcgi;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Middleware\MiddlewareSupported;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Socket\SocketServer;
use KoolKode\Async\Socket\SocketServerFactory;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerInterface;

/**
 * Provides a FastCGI responder based on a TCP or unix socket server.
 * 
 * @author Martin Schröder
 */
class FcgiEndpoint
{
    use MiddlewareSupported;
    
    /**
     * @var SocketServerFactory
     */
    protected $factory;
    
    /**
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * @var SocketServer
     */
    protected $server;
    
    public function __construct(string $peer = '0.0.0.0:0', string $peerName = 'localhost', LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    
        $this->factory = new SocketServerFactory($peer);
        $this->factory->setPeerName($peerName);
    }
    
    public function getSocketServerFactory(): SocketServerFactory
    {
        return $this->factory;
    }
    
    public function listen(callable $action): Awaitable
    {
        return new Coroutine(function () use ($action) {
            $factory = clone $this->factory;
            $factory->setTcpNoDelay(true);
            
            $this->server = yield $factory->createSocketServer();
            
            $context = new HttpDriverContext($factory->getPeerName(), $factory->isEncrypted(), $this->middlewares);
            $pending = new \SplObjectStorage();
            
            try {
                yield $this->server->listen(function (SocketStream $socket) use ($pending, $context, $action) {
                    $conn = new Connection($socket, $context, $this->logger);
                    
                    $pending->attach($conn);
                    
                    while (null !== ($next = yield $conn->nextRequest())) {
                        new Coroutine($this->processRequest($conn, $action, ...$next));
                    }
                });
            } finally {
                $this->server = null;
                
                foreach ($pending as $task) {
                    $task->cancel(new \RuntimeException('FCGI server stopped'));
                }
            }
        });
    }
    
    protected function processRequest(Connection $conn, callable $action, Handler $handler, HttpRequest $request): \Generator
    {
        $next = new NextMiddleware($this->middlewares, function (HttpRequest $request) use ($action) {
            $result = $action($request);
            
            if ($result instanceof \Generator) {
                $result = yield from $result;
            }
            
            if (!$result instanceof HttpResponse) {
                return new HttpResponse(Http::INTERNAL_SERVER_ERROR);
            }
            
            return $result;
        });
        
        yield from $handler->sendResponse($request, yield from $next($request));
    }
}
