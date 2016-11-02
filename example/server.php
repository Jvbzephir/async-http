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

use Interop\Async\Loop;
use KoolKode\Async\Http\FileBody;
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http2\Driver as Http2Driver;
use KoolKode\Async\Http\WebSocket\ConnectionHandler;
use KoolKode\Async\Test\TestLogger;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/websocket.php';

Loop::execute(function () {
    $logger = new TestLogger(STDERR);
    
    $websocket = new ExampleEndpoint();
    
    $endpoint = new HttpEndpoint('0.0.0.0:8888', 'localhost', $logger);
    $endpoint->setCertificate(__DIR__ . '/localhost.pem');
    
    $endpoint->addDriver(new Http2Driver(null, $logger));
    $endpoint->addUpgradeResultHandler(new ConnectionHandler($logger));
    
    $endpoint->listen(function (HttpRequest $request) use ($websocket) {
        switch (trim($request->getRequestTarget(), '/')) {
            case 'websocket':
                return $websocket;
            case 'websocket.js':
                $type = 'text/javascript';
                $file = 'websocket.js';
                break;
            case 'bigbang.jpg':
                $type = 'image/jpeg';
                $file = 'big-bang-theory.jpg';
                break;
            default:
                $type = 'text/html; charset="utf-8"';
                $file = 'index.html';
        }
        
        $response = new HttpResponse();
        $response = $response->withHeader('Content-Type', $type);
        $response = $response->withBody(new FileBody(__DIR__ . '/public/' . $file));
        
        return $response;
    });
    
    echo "HTTPS server listening on port 8888\n\n";
});
