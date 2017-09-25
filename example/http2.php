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
use KoolKode\Async\Http\Http2\ClientConnectionFactory;
use KoolKode\Async\Log\PipeLogHandler;
use KoolKode\Async\Socket\ClientEncryption;
use KoolKode\Async\Socket\ClientFactory;
use KoolKode\Async\Socket\Connect;

error_reporting(-1);
ini_set('display_errors', false);

require_once '../vendor/autoload.php';

$factory = new ContextFactory();
$factory->registerLogger(new PipeLogHandler());

$factory->createContext()->run(function (Context $context) {
    $tls = new ClientEncryption(ClientEncryption::TLS_1_2);
    $tls = $tls->withPeerName('http2.golang.org');
    $tls = $tls->withAlpnProtocols('h2');
    
    $factory = new ClientFactory('tcp://http2.golang.org:443', $tls);
    $stream = yield $factory->connect($context, (new Connect())->withTcpNodelay(true));
    
    $factory = new ClientConnectionFactory();
    $conn = yield $factory->connectClient($context, $stream);
    
    try {
        $context->info('Pink took {time} milliseconds', [
            'time' => yield $conn->ping($context)
        ]);
        
        $response = yield $conn->send($context, new HttpRequest('https://http2.golang.org/ECHO', Http::PUT, [], new StringBody('Hello World!')));
        
        $context->info('HTTP/2 response received', [
            'status' => $response->getStatusCode(),
            'body' => yield $response->getBody()->getContents($context)
        ]);
    } finally {
        $conn->close();
    }
});
