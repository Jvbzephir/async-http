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
        } else {
            $body = array_map('trim', explode("\n", trim($body)));
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
    $client = $client->withMiddleware(new ResponseContentDecoder());
    $client = $client->withBaseUri('https://http2.golang.org/');
    
    yield from $log($context, yield $client->request('reqinfo')->send($context));
    
    $request = $client->request('ECHO', Http::PUT);
    $request->text('Hello World!')->expectContinue(true);
    
    yield from $log($context, yield $request->send($context));
    
    $client = $client->withBaseUri('http://httpbin.org/');
    
    yield from $log($context, yield $client->request('anything', Http::POST)->form([
        'foo' => 'bar'
    ])->send($context));
    
    $context->info('Request JSON from the server', [
        'result' => yield $client->request('/gzip')->loadJson($context)
    ]);
});
