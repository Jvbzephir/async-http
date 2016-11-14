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
use KoolKode\Async\Http\Middleware\PublishFiles;
use KoolKode\Async\Http\Response\FileResponse;
use KoolKode\Async\Http\WebSocket\ConnectionHandler;
use KoolKode\Async\Log\PipeLogHandler;
use KoolKode\Async\Loop\LoopConfig;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/websocket.php';

Loop::execute(function () {
    $logger = LoopConfig::getLogger();
    $logger->addHandler(new PipeLogHandler());
    
    $websocket = new ExampleEndpoint();
    
    $endpoint = new HttpEndpoint('0.0.0.0:8080', 'localhost', $logger);
    $endpoint->addUpgradeResultHandler(new ConnectionHandler($logger));
    
    $endpoint->addMiddleware(new PublishFiles(__DIR__ . '/public', '/asset'));
    
    $endpoint->listen(function (HttpRequest $request) use ($websocket) {
        switch (trim($request->getRequestTarget(), '/')) {
            case 'websocket':
                return $websocket;
            case '':
                return new FileResponse(__DIR__ . '/public/index.html');
        }
        
        return new HttpResponse(Http::NOT_FOUND);
    });
    
    echo "HTTP server listening on port 8080\n\n";
});
