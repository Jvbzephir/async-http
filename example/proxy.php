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
use KoolKode\Async\Http\Http1\Driver as Http1Driver;
use KoolKode\Async\Http\Events\EventResponder;
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\Middleware\BrowserSupport;
use KoolKode\Async\Http\Middleware\ContentEncoder;
use KoolKode\Async\Http\Middleware\PublishFiles;
use KoolKode\Async\Http\ReverseProxySettings;
use KoolKode\Async\Http\WebSocket\ConnectionHandler;
use KoolKode\Async\Log\Logger;
use KoolKode\Async\Log\PipeLogHandler;

require_once __DIR__ . '/../vendor/autoload.php';

Loop::execute(function () {
    $logger = Logger::get();
    $logger->addHandler(new PipeLogHandler());
    
    $ws = new ConnectionHandler();
    $ws->setLogger($logger);
    
    $driver = new Http1Driver();
    $driver->setLogger($logger);
    $driver->addUpgradeResultHandler($ws);
    
    $endpoint = new HttpEndpoint('0.0.0.0:8080', 'localhost', $driver);
    $endpoint->setProxySettings(new ReverseProxySettings('127.0.0.1', '::1', '10.0.2.2'));
    
    $endpoint->addMiddleware(new ContentEncoder());
    $endpoint->addMiddleware(new BrowserSupport());
    $endpoint->addMiddleware(new PublishFiles(__DIR__ . '/public', '/asset'));
    $endpoint->addResponder(new EventResponder());
    
    $endpoint->listen(require __DIR__ . '/listener.php');
    
    echo "HTTP server listening on port 8080\n\n";
});
