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
use KoolKode\Async\Http\Middleware\ResponseContentDecoder;
use KoolKode\Async\Log\PipeLogHandler;

error_reporting(-1);
ini_set('display_errors', false);

require_once '../vendor/autoload.php';

$factory = new ContextFactory();
$factory->registerLogger(new PipeLogHandler(PipeLogHandler::STDOUT), PipeLogHandler::INFO);

$factory->createContext()->run(function (Context $context) {
    $log = function (Context $context, HttpResponse $response) {
        $body = yield $response->getBody()->getContents($context);
        
        if ($response->getContentType()->getMediaType()->is('*/json')) {
            $body = json_decode($body, true);
        }
        
        $context->info('HTTP/{version} response received', [
            'version' => $response->getProtocolVersion(),
            'status' => $response->getStatusCode(),
            'headers' => array_map(function (array $h) {
                return implode(', ', $h);
            }, $response->getHeaders()),
            'body' => $body
        ]);
    };
    
    $manager = new ConnectionManager($context->getLoop());
    
    $client = new HttpClient(new Http1Connector($manager), new Http2Connector());
    $client->addMiddleware(new ResponseContentDecoder());
    
    yield from $log($context, yield $client->get($context, 'https://http2.golang.org/reqinfo'));
    
    yield from $log($context, yield $client->send($context, new HttpRequest('https://http2.golang.org/ECHO', Http::PUT, [
        'Content-Type' => 'text/plain'
    ], new StringBody('Hello World!'))));
    
    yield from $log($context, yield $client->get($context, 'https://httpbin.org/gzip'));
    
    $context->info('Request JSON from the server', [
        'result' => yield $client->getJson($context, 'http://httpbin.org/deflate')
    ]);
});
