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
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http2\Driver as Http2Driver;
use KoolKode\Async\Http\Middleware\ContentEncoder;
use KoolKode\Async\Http\Middleware\PublishFiles;
use KoolKode\Async\Http\Response\FileResponse;
use KoolKode\Async\Http\WebSocket\ConnectionHandler;
use KoolKode\Async\Test\TestLogger;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/websocket.php';

Loop::execute(function () {
    $websocket = new ExampleEndpoint();
    
    $endpoint = new HttpEndpoint('0.0.0.0:8888', 'localhost', $logger = new TestLogger(STDERR));
    $endpoint->setCertificate(__DIR__ . '/localhost.pem');
    
    $endpoint->addDriver(new Http2Driver(null, $logger));
    
    $ws = new ConnectionHandler($logger);
    $ws->setDeflateSupported(true);
    
    $endpoint->addUpgradeResultHandler($ws);
    
    $endpoint->addMiddleware(new PublishFiles(__DIR__ . '/public', '/asset'));
    $endpoint->addMiddleware(new ContentEncoder());
    
    $endpoint->listen(function (HttpRequest $request) use ($websocket) {
        switch (trim($request->getRequestTarget(), '/')) {
            case 'websocket':
                return $websocket;
            case '':
                return new FileResponse(__DIR__ . '/public/index.html');
        }
        
        return new HttpResponse(Http::NOT_FOUND);
    });
    
    echo "HTTPS server listening on port 8888\n\n";
});
