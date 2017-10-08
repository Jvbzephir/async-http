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
use KoolKode\Async\Http\Body\StringBody;
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
    
    $requests = [
        new HttpRequest('http://httpbin.org/anything', Http::PUT, [
            'Content-Type' => 'application/json',
            'User-Agent' => 'PHP/' . PHP_VERSION
        ], new StringBody('{"message":"Hello Server :)"}')),
        new HttpRequest('http://httpbin.org/anything'),
        new HttpRequest('http://httpbin.org/anything')
    ];
    
    yield $client->pipe($requests)->map(function (Context $context, HttpResponse $response) {
        echo yield $response->getBody()->getContents($context);
    })->dispatch($context);
});
