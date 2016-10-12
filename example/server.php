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
use KoolKode\Async\Http\Http2\Driver as Http2Driver;
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
    $endpoint = new HttpEndpoint('0.0.0.0:8888');
    $endpoint->setCertificate(__DIR__ . '/localhost.pem');
    
    $endpoint->addDriver(new Http2Driver(null, $logger));
    
    $endpoint->listen();
    
    echo "HTTPS server listening on port 8888\n\n";
});
