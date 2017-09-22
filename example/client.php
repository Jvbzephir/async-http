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
use KoolKode\Async\Http\Http1\Connector;

error_reporting(-1);
ini_set('display_errors', false);

require_once '../vendor/autoload.php';

$context = (new ContextFactory())->createContext();

$context->run(function (Context $context) {
    $client = new Connector();
    
    $request = new HttpRequest('http://httpbin.org/anything', Http::PUT, [
        'Content-Type' => 'application/json',
        'User-Agent' => 'PHP/' . PHP_VERSION
    ], new StringBody('{"message":"Hello Server :)"}'));
    
    $response = $client->send($context, $request);
    
    print_r($response = yield $response);
    
    $body = yield $response->getBody()->getContents($context);
    $json = json_decode($body, true);
    
    print_r($json);
});
