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
use KoolKode\Async\Http\Endpoint;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Middleware\MiddlewareSupported;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Http\Responder\ResponderSupported;
use KoolKode\Async\Http\Logger;
use KoolKode\Async\Socket\SocketServer;
use KoolKode\Async\Socket\SocketServerFactory;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Provides a FastCGI responder based on a TCP or unix socket server.
 * 
 * @author Martin Schröder
 */
class FcgiEndpoint implements Endpoint, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use MiddlewareSupported;
    use ResponderSupported;
    
    /**
     * @var SocketServerFactory
     */
    protected $factory;
    
    /**
     * @var SocketServer
     */
    protected $server;
    
    public function __construct(string $peer = '0.0.0.0:0', string $peerName = 'localhost')
    {
        $this->factory = new SocketServerFactory($peer, (($peer[0] ?? '') === '/') ? 'unix' : 'tcp');
        $this->factory->setPeerName($peerName);
        
        $this->logger = new Logger(static::class);
    }
    
    public function getSocketServerFactory(): SocketServerFactory
    {
        return $this->factory;
    }
    
    /**
     * {@inheritdoc}
     */
    public function listen(callable $action): Awaitable
    {
        return new Coroutine(function () use ($action) {
            $factory = clone $this->factory;
            $factory->setTcpNoDelay(true);
            
            $this->server = yield $factory->createSocketServer();
            
            $port = $this->server->getPort();
            $peer = $this->server->getAddress() . ($port ? (':' . $port) : '');
            
            $context = new HttpDriverContext($peer, $factory->getPeerName(), $factory->isEncrypted(), $this->middlewares, $this->responders);
            $pending = new \SplObjectStorage();
            
            try {
                yield $this->server->listen(function (SocketStream $socket) use ($pending, $context, $action) {
                    $pending->attach($conn = new Connection($socket, $context));
                    
                    while (null !== ($next = yield $conn->nextRequest())) {
                        new Coroutine($this->processRequest($conn, $context, $action, ...$next));
                    }
                });
            } finally {
                $this->server = null;
                
                foreach ($pending as $task) {
                    $task->cancel('FCGI server stopped');
                }
            }
        });
    }

    protected function processRequest(Connection $conn, HttpDriverContext $context, callable $action, Handler $handler, HttpRequest $request): \Generator
    {
        $next = new NextMiddleware($this->middlewares, function (HttpRequest $request) use ($context, $action) {
            $result = $action($request, $context);
            
            if ($result instanceof \Generator) {
                $result = yield from $result;
            }
            
            return $context->respond($request, $result);
        });
        
        yield from $handler->sendResponse($request, yield from $next($request));
    }
}
