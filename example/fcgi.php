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

use AsyncInterop\Loop;
use KoolKode\Async\Context;
use KoolKode\Async\Http\Fcgi\FcgiEndpoint;
use KoolKode\Async\Http\Http1\Driver as Http1Driver;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Middleware\BrowserSupport;
use KoolKode\Async\Http\Middleware\ContentEncoder;
use KoolKode\Async\Http\Middleware\PublishFiles;
use KoolKode\Async\Http\WebSocket\ConnectionHandler;
use KoolKode\Async\Log\Logger;
use KoolKode\Async\Log\PipeLogHandler;

require_once __DIR__ . '/../vendor/autoload.php';

Loop::execute(function () {
    Context::invoke(function () {
        $endpoint = new FcgiEndpoint('0.0.0.0:9090', 'localhost');
        
        $endpoint->addMiddleware(new PublishFiles(__DIR__ . '/public', '/asset'));
        $endpoint->addMiddleware(new BrowserSupport());
        $endpoint->addMiddleware(new ContentEncoder());
        
        $endpoint->addResponder(new \KoolKode\Async\Http\Events\EventResponder());
        
        $endpoint->listen(require __DIR__ . '/listener.php');
        
        echo "FCGI server listening on port 9090\n";
        
        $ws = new ConnectionHandler();
        
        $driver = new Http1Driver();
        $driver->addUpgradeResultHandler($ws);
        
        $http = new HttpEndpoint('0.0.0.0:8080', 'localhost', $driver);
        
        $websocket = new ExampleEndpoint();
        
        $http->listen(function (HttpRequest $request) use ($websocket) {
            if ('websocket' === \trim($request->getRequestTarget(), '/')) {
                return $websocket;
            }
            
            return new HttpResponse(Http::NOT_FOUND);
        });
        
        echo "WebSocket server listening on port 8080\n\n";
    }, Context::inherit([
        Logger::class => new Logger(new PipeLogHandler())
    ]));
});
