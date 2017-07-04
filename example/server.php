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
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\Events\EventResponder;
use KoolKode\Async\Http\Http1\Driver as Http1Driver;
use KoolKode\Async\Http\Http2\Driver as Http2Driver;
use KoolKode\Async\Http\Middleware\BrowserSupport;
use KoolKode\Async\Http\Middleware\PublishFiles;
use KoolKode\Async\Http\Middleware\RequestContentDecoder;
use KoolKode\Async\Http\Middleware\ResponseContentEncoder;
use KoolKode\Async\Http\WebSocket\ConnectionHandler;
use KoolKode\Async\Log\Logger;
use KoolKode\Async\Log\PipeLogHandler;
use Psr\Log\LogLevel;

require_once __DIR__ . '/../vendor/autoload.php';

Loop::execute(function () {
    Context::invoke(function () {
        $ws = new ConnectionHandler();
        $ws->setDeflateSupported(true);
        
        $http2 = new Http2Driver();
        
        $http1 = new Http1Driver();
        $http1->addUpgradeHandler($http2);
        $http1->addUpgradeResultHandler($ws);
        
        $endpoint = new HttpEndpoint('0.0.0.0:8888', 'localhost', $http1, $http2);
        $endpoint->setCertificate(__DIR__ . '/localhost.pem');
        
        $endpoint->addMiddleware(new PublishFiles(__DIR__ . '/public', '/asset'));
        $endpoint->addMiddleware(new BrowserSupport());
        $endpoint->addMiddleware(new RequestContentDecoder());
        $endpoint->addMiddleware(new ResponseContentEncoder());
        
        $endpoint->addResponder(new EventResponder());
        
        $endpoint->listen(require __DIR__ . '/listener.php');
        
        echo "HTTPS server listening on port 8888\n\n";
    }, Context::inherit([
        Logger::class => new Logger(new PipeLogHandler(STDERR, LogLevel::DEBUG))
    ]));
});
