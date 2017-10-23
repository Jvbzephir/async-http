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
use KoolKode\Async\Log\PipeLogHandler;

error_reporting(-1);
ini_set('display_errors', false);

require_once '../vendor/autoload.php';

$factory = new ContextFactory();
$factory->registerLogger(new PipeLogHandler());

$factory->createContext()->run(function (Context $context) {
    $manager = new ConnectionManager($context->getLoop());
    $client = new HttpClient(new Http1Connector($manager));
    $client = $client->withBaseUri('http://httpbin.org/anything');
    
    $requests = [
        $client->put('/anything', [
            'User-Agent' => 'PHP/' . PHP_VERSION
        ])->json([
            'message' => 'Hello Server :)'
        ]),
        $client->get('https://httpbin.org/anything'),
        $client->get()
    ];
    
    yield $client->sendAll($requests)->map(function (Context $context, HttpResponse $response) {
        echo yield $response->getBody()->getContents($context);
    })->dispatch($context);
});
