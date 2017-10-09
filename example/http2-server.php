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
use KoolKode\Async\Http\Http2\Http2Driver;
use KoolKode\Async\Http\Response\JsonResponse;
use KoolKode\Async\Log\PipeLogHandler;
use KoolKode\Async\Socket\ServerEncryption;
use KoolKode\Async\Socket\ServerFactory;
use KoolKode\Async\Test\AsyncTestCase;

error_reporting(-1);
ini_set('display_errors', false);

require_once '../vendor/autoload.php';

$factory = new ContextFactory();
$factory->registerLogger(new PipeLogHandler(PipeLogHandler::STDOUT));

$factory->createContext()->run(function (Context $context) {
    $driver = new Http2Driver();
    
    $tls = new ServerEncryption(ServerEncryption::TLS_1_2);
    $tls = $tls->withDefaultCertificate(AsyncTestCase::getLocalhostCertificateFile());
    $tls = $tls->withPeerName('localhost')->withAlpnProtocols(...$driver->getProtocols());
    
    $factory = new ServerFactory('tcp://127.0.0.1:8080', $tls);
    $server = $factory->createServer($context->getLoop());
    
    try {
        $socket = yield $server->accept($context);
        
        $driver->listen($context, $socket, function (Context $context, HttpRequest $request) {
            return new JsonResponse([
                'dispatcher' => __FILE__
            ]);
        });
    } finally {
        $server->close();
    }
});
