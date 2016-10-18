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
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http2\Driver as Http2Driver;
use KoolKode\Async\Http\StringBody;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

require_once __DIR__ . '/../vendor/autoload.php';

$logger = new class() implements LoggerInterface {
    use LoggerTrait;

    public function log($level, $message, array $context = [])
    {
        fwrite(STDERR, sprintf("[%s] %s%s", strtoupper($level), $message, PHP_EOL));
    }
};

Loop::setErrorHandler(function (\Throwable $e) {
    fwrite(STDERR, "$e\n\n");
});

Loop::execute(function () use ($logger) {
    $endpoint = new HttpEndpoint('0.0.0.0:8888', 'localhost', $logger);
    $endpoint->setCertificate(__DIR__ . '/localhost.pem');
    
    $endpoint->addDriver(new Http2Driver(null, $logger));
    
    $endpoint->listen(function (HttpRequest $request) {
        $response = new HttpResponse();
        $response = $response->withHeader('Content-Type', 'text/plain');
        $response = $response->withBody(new StringBody(sprintf('ACTION: %s:%u', __FILE__, __LINE__)));
        
        return $response;
    });
    
    echo "HTTPS server listening on port 8888\n\n";
});
