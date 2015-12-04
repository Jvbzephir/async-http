<?php

/*
 * This file is part of KoolKode Async.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use KoolKode\Async\ExecutorFactory;
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\Http2\Http2Driver;
use KoolKode\Async\Log\Logger;
use KoolKode\Async\SystemCall;
use KoolKode\K1\App;
use KoolKode\K1\K1;
use KoolKode\Stream\ResourceInputStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LogLevel;

error_reporting(-1);
ini_set('display_errors', false);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$executor = (new ExecutorFactory())->createExecutor();

$file = __DIR__ . '/config.local.php';
if (is_file($file)) {
    call_user_func(require $file, $executor);
}

$executor->setErrorHanndler(function (\Throwable $e) {
    fwrite(STDERR, $e . "\n\n");
});

$executor->runNewTask(call_user_func(function () {
    
    $logger = new Logger(yield SystemCall::newEventEmitter(), $_SERVER['argv'][1] ?? LogLevel::INFO);
    
    $app = new App((new K1())->build());
    
    $app->route('index', '/', function (ServerRequestInterface $request) {
        return sprintf('You are connected using HTTP/%s', $request->getProtocolVersion());
    });
    
    $app->route('favicon', 'GET /favicon.ico', function (ResponseInterface $response) {
        $response = $response->withHeader('Content-Type', 'image/vnd.microsoft.icon');
        $response = $response->withHeader('Cache-Control', 'public,max-age=3600');
        $response = $response->withBody(ResourceInputStream::fromUrl(__DIR__ . '/content/favicon.ico'));
        
        return $response;
    });
    
    $app->route('html', 'GET /test.html', function (ResponseInterface $response) use($app) {
        $response = $response->withHeader('Content-Type', 'text/html');
        $response = $response->withBody(ResourceInputStream::fromUrl(__DIR__ . '/content/test.html'));
        
        return $response->withAddedHeader('K1-Push-Promise', 'bigbang');
    });
    
    $app->route('greeting', '/greet/{name}', function ($name) {
        return sprintf('Hello %s', $name);
    });
    
    $app->route('bigbang', 'GET /img/bigbang.jpg', function (ResponseInterface $response) {
        $response = $response->withHeader('Content-Type', 'image/jpeg');
        $response = $response->withHeader('Cache-Control', 'public,max-age=30');
        $response = $response->withBody(ResourceInputStream::fromUrl(__DIR__ . '/content/big-bang-theory.jpg'));
        
        return $response;
    });
    
    $http = new HttpEndpoint(8000, '0.0.0.0', 'test.k1');
    $http->setCertificate(__DIR__ . '/cert.pem', true);
    
    $http->setLogger($logger);
    $http->getHttp1Driver()->setLogger($logger);
    
    $http->addDriver(new Http2Driver($logger));
    
    $action = function ($request, $response) use ($app, $http) {
        $response = $app($request, $response);
        
        $factory = $http->getHttp1Driver()->getHttpFactory();
        $base = $request->getUri();
        $push = [];
        
        foreach ($response->getHeader('K1-Push-Promise') as $route) {
            $uri = $factory->createUri($app->resolve($route));
            $uri = $uri->withScheme($base->getScheme());
            $uri = $uri->withHost($base->getHost());
            $uri = $uri->withPort($base->getPort());
            
            $push[] = $app($factory->createServerRequest()->withUri($uri)->withRequestTarget($uri->getPath()), $factory->createResponse());
        }
        
        return [
            $response->withoutHeader('K1-Push-Promise'),
            $push
        ];
    };
    
    yield SystemCall::runTask($http->run($action), $http->getTitle());
}));

$executor->run();
