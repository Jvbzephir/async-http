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
use KoolKode\Async\Http\WebSocket\WebSocketClient;
use KoolKode\Async\Log\PipeLogHandler;

error_reporting(-1);
ini_set('display_errors', false);

require_once '../vendor/autoload.php';

$factory = new ContextFactory();
$factory->registerLogger(new PipeLogHandler());

$factory->createContext()->run(function (Context $context) {
    $manager = new ConnectionManager($context->getLoop());
    $client = new WebSocketClient(new HttpClient(new Http1Connector($manager)));
    
    $conn = yield $client->connect($context, 'wss://echo.websocket.org/');
    
    try {
        yield $conn->sendText($context, 'Hello World :)');
        
        $context->info('Received: {message}', [
            'message' => yield $conn->receive($context)
        ]);
    } finally {
        $conn->close();
    }
});
