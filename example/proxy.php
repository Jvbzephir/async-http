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
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\Middleware\PublishFiles;
use KoolKode\Async\Http\ReverseProxySettings;
use KoolKode\Async\Http\WebSocket\ConnectionHandler;
use KoolKode\Async\Log\PipeLogHandler;
use KoolKode\Async\Loop\LoopConfig;

require_once __DIR__ . '/../vendor/autoload.php';

Loop::execute(function () {
    $logger = LoopConfig::getLogger();
    $logger->addHandler(new PipeLogHandler());
    
    $endpoint = new HttpEndpoint('0.0.0.0:8080', 'localhost', $logger);
    $endpoint->setProxySettings(new ReverseProxySettings('127.0.0.1', '::1', '10.0.2.2'));
    
    $endpoint->addUpgradeResultHandler(new ConnectionHandler($logger));
    
    $endpoint->addMiddleware(new PublishFiles(__DIR__ . '/public', '/asset'));
    
    $endpoint->listen(require __DIR__ . '/listener.php');
    
    echo "HTTP server listening on port 8080\n\n";
});
