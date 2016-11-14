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
use KoolKode\Async\Http\Response\JsonResponse;
use KoolKode\Async\Log\PipeLogHandler;
use KoolKode\Async\Loop\LoopConfig;

require_once __DIR__ . '/../vendor/autoload.php';

Loop::execute(function () {
    $logger = LoopConfig::getLogger();
    $logger->addHandler(new PipeLogHandler());
    
    $endpoint = new HttpEndpoint('0.0.0.0:8080', 'localhost', $logger);
    
    $endpoint->listen(function (HttpRequest $request) {
        return new JsonResponse([
            'server' => __FILE__,
            'uri' => (string) $request->getUri()
        ]);
    });
    
    echo "HTTP server listening on port 8080\n\n";
});
