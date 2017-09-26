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
use KoolKode\Async\Http\Http2\Http2Connector;
use KoolKode\Async\Log\PipeLogHandler;

error_reporting(-1);
ini_set('display_errors', false);

require_once '../vendor/autoload.php';

$factory = new ContextFactory();
$factory->registerLogger(new PipeLogHandler(PipeLogHandler::STDOUT), PipeLogHandler::INFO);

$factory->createContext()->run(function (Context $context) {
    $manager = new ConnectionManager($context->getLoop());
    $client = new HttpClient(new Http1Connector($manager), new Http2Connector());
    
    $response = yield $client->send($context, new HttpRequest('https://http2.golang.org/ECHO', Http::PUT, [], new StringBody('Hello World!')));
    
    $context->info('HTTP/{version} response received', [
        'version' => $response->getProtocolVersion(),
        'status' => $response->getStatusCode(),
        'headers' => array_map(function (array $h) {
            return implode(', ', $h);
        }, $response->getHeaders()),
        'body' => yield $response->getBody()->getContents($context)
    ]);
    
    $response = yield $client->send($context, new HttpRequest('https://httpbin.org/anything', Http::POST, [
        'Content-Type' => 'application/json',
        'User-Agent' => 'PHP/' . PHP_VERSION
    ], new StringBody('{"message":"Hello Server :)"}')));
    
    $context->info('HTTP/{version} response received', [
        'version' => $response->getProtocolVersion(),
        'status' => $response->getStatusCode(),
        'headers' => array_map(function (array $h) {
            return implode(', ', $h);
        }, $response->getHeaders()),
        'body' => json_decode(yield $response->getBody()->getContents($context), true)
    ]);
});
