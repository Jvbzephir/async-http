<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

use KoolKode\Async\Context;
use KoolKode\Async\ContextFactory;
use KoolKode\Async\Http\Http1\ConnectionManager;
use KoolKode\Async\Http\Http1\Http1Connector;
use KoolKode\Async\Http\Http1\Http1Driver;
use KoolKode\Async\Http\WebSocket\Connection;
use KoolKode\Async\Http\WebSocket\WebSocketClient;
use KoolKode\Async\Http\WebSocket\WebSocketEndpoint;
use KoolKode\Async\Http\WebSocket\WebSocketServer;
use KoolKode\Async\Log\PipeLogHandler;

error_reporting(-1);
ini_set('display_errors', false);

require_once '../vendor/autoload.php';

$factory = new ContextFactory();
$factory->registerLogger(new PipeLogHandler());

$factory->createContext()->run(function (Context $context) {
    $manager = new ConnectionManager($context->getLoop());
    $client = new WebSocketClient(new HttpClient(new Http1Connector($manager)), true);
    
    $server = new HttpEndpoint((new Http1Driver())->withUpgradeResultHandler(new WebSocketServer(true)));
    
    $server = $server->withAddress('tcp://127.0.0.1:8080');
    
    $server = $server->withDefaultHost(new HttpHost(function (Context $context, HttpRequest $request) {
        if ($request->getUri()->getPath() == '/websocket') {
            return new class() extends WebSocketEndpoint {

                public function onTextMessage(Context $context, Connection $conn, string $message)
                {
                    $context->info('Server received: {message}', [
                        'message' => $message
                    ]);
                    
                    yield $conn->sendText($context, strtoupper($message));
                }
            };
        }
        
        return new HttpResponse(Http::NOT_FOUND);
    }));
    
    Context::rethrow($context->task(function (Context $context) use ($server) {
        yield $server->listen($context);
    }));
    
    $conn = yield $client->connect($context, 'ws://localhost:8080/websocket');
    
    try {
        yield $conn->sendText($context, 'Hello World :)');
        
        $context->info('Client received: {message}', [
            'message' => yield $conn->receive($context)
        ]);
    } finally {
        $conn->close();
    }
    
    yield $context->delay(100);
}, true);
