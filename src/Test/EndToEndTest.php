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

namespace KoolKode\Async\Http\Test;

use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Http1\Connector;
use KoolKode\Async\Http\Http1\Driver;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Test\AsyncTestCase;

abstract class EndToEndTest extends AsyncTestCase
{
    protected $clientMiddleware;
    
    protected $serverMiddleware;
    
    protected $driver;
    
    protected $connector;
    
    protected $serverTasks = [];

    protected function setUp()
    {
        parent::setUp();
        
        $this->clientMiddleware = new \SplPriorityQueue();
        $this->serverMiddleware = new \SplPriorityQueue();
        
        $this->driver = new Driver();
        $this->driver->setDebug(true);
        
        $this->connector = new Connector();
    }

    protected function send(HttpRequest $request, callable $action): \Generator
    {
        $next = new NextMiddleware($this->clientMiddleware, function (HttpRequest $request) use ($action) {
            $context = $this->connector->getConnectorContext($request->getUri());
            
            if (!$context->connected) {
                list ($a, $b) = Socket::createPair();
                
                $context->socket = new SocketStream($a);
                
                $serverContext = new HttpDriverContext('localhost', $this->serverMiddleware);
                
                $this->serverTasks[] = $this->driver->handleConnection($serverContext, new SocketStream($b), $action);
            }
            
            return yield $this->connector->send($context, $request);
        });
        
        return yield from $next($request);
    }

    protected function disposeTest()
    {
        try {
            foreach ($this->serverTasks as $task) {
                $task->cancel(new \RuntimeException('Test completed'));
            }
        } finally {
            $this->serverTasks = [];
        }
    }
}
