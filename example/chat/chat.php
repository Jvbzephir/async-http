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
use KoolKode\Async\Http\Http1\Http1Driver;
use KoolKode\Async\Http\Response\FileResponse;
use KoolKode\Async\Http\Response\TextResponse;
use KoolKode\Async\Http\WebSocket\WebSocketServer;
use KoolKode\Async\Log\PipeLogHandler;

error_reporting(-1);
ini_set('display_errors', false);

require_once '../../vendor/autoload.php';

$factory = new ContextFactory();
$factory->registerLogger(new PipeLogHandler(PipeLogHandler::STDOUT));

$factory->createContext()->run(function (Context $context) {
    $tpl = file_get_contents(__DIR__ . '/index.html');
    $websocket = require __DIR__ . '/endpoint.php';
    
    $host = new HttpHost(function (Context $context, HttpRequest $request) use ($tpl, $websocket) {
        switch ($request->getUri()->getPath()) {
            case '/chat.sock':
                return $websocket;
            case '/chat.js':
                return new FileResponse(__DIR__ . '/chat.js');
            case '/':
                return new TextResponse(strtr($tpl, [
                    '###URI###' => $request->getUri()
                ]), 'text/html');
        }
        
        return new HttpResponse(Http::NOT_FOUND);
    });
    
    $driver = new Http1Driver();
    $driver = $driver->withUpgradeResultHandler(new WebSocketServer());
    
    $endpoint = new HttpEndpoint($driver);
    $endpoint = $endpoint->withAddress('tcp://127.0.0.1:8080');
    $endpoint = $endpoint->withDefaultHost($host);
    
    yield $endpoint->listen($context);
});
