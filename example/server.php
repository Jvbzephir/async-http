<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use KoolKode\Async\ExecutorFactory;
use KoolKode\Async\Http\Fcgi\FcgiEndpoint;
use KoolKode\Async\Http\FileBody;
use KoolKode\Async\Http\Http2\Http2Driver;
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StringBody;
use KoolKode\Async\Log\Logger;
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

$executor->runCallback(function () use ($executor) {
    
    if (class_exists(Logger::class)) {
        $logger = new Logger($executor, 'php://stderr', $_SERVER['argv'][1] ?? LogLevel::INFO);
    } else {
        $logger = NULL;
    }
    
    $http = new HttpEndpoint(8888, '0.0.0.0', 'test.k1');
    $http->setCertificate(__DIR__ . '/cert.pem', true);
    
    $http->setLogger($logger);
    $http->getHttp1Driver()->setLogger($logger);
    
    $http->addDriver(new Http2Driver($logger));
    
    $action = function (HttpRequest $request, HttpResponse $response) use ($http) {
        if ($request->hasQueryParam('source')) {
            return $response->withBody(new FileBody(__FILE__));
        }
        
        $response = $response->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response = $response->withHeader('Test2', json_encode($request->getHeaders()));
        
        return $response->withBody(new StringBody('KoolKode Async HTTP :)'));
    };
    
//     $fcgi = new FcgiEndpoint(4000, '0.0.0.0', $logger);
    
    $executor->runNewTask($http->run($action), $http->getTitle());
//     $executor->runNewTask($fcgi->run($action), $fcgi->getTitle());
});

$executor->run();
