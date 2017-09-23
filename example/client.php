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
use KoolKode\Async\Log\PipeLogHandler;

error_reporting(-1);
ini_set('display_errors', false);

require_once '../vendor/autoload.php';

$factory = new ContextFactory();
$factory->registerLogger(new PipeLogHandler());

$factory->createContext()->run(function (Context $context) {
    $client = new HttpClient();
    
    $request = new HttpRequest('http://httpbin.org/anything', Http::PUT, [
        'Content-Type' => 'application/json',
        'User-Agent' => 'PHP/' . PHP_VERSION
    ], new StringBody('{"message":"Hello Server :)"}'));
    
    $response1 = yield $client->send($context, $request);
    
    list ($response1, $response2) = yield $context->all([
        $client->send($context, $request),
        $client->send($context, new HttpRequest('http://httpbin.org/anything'))
    ]);
    
    echo yield $response1->getBody()->getContents($context), "\n";
    echo yield $response2->getBody()->getContents($context), "\n";
});
