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
use KoolKode\Async\Http\Http1\Http1Driver;
use KoolKode\Async\Http\Http2\Http2Driver;
use KoolKode\Async\Http\Middleware\ResponseContentEncoder;
use KoolKode\Async\Http\Response\RedirectResponse;
use KoolKode\Async\Http\Response\TextResponse;
use KoolKode\Async\Log\PipeLogHandler;
use KoolKode\Async\Test\AsyncTestCase;

error_reporting(-1);
ini_set('display_errors', false);

require_once '../vendor/autoload.php';

$factory = new ContextFactory();
$factory->registerLogger(new PipeLogHandler(PipeLogHandler::STDOUT));

$factory->createContext()->run(function (Context $context) {
    $host = (new HttpHost(function () {
        return new TextResponse(__FILE__);
    }))->withEncryption('localhost', AsyncTestCase::getLocalhostCertificateFile());
    
    $endpoint = new HttpEndpoint(new Http1Driver(), new Http2Driver());
    $endpoint = $endpoint->withAddress('tcp://127.0.0.1:80');
    $endpoint = $endpoint->withAddress('tcp://127.0.0.1:443', true);
    $endpoint = $endpoint->withDefaultHost($host);
    
    $endpoint = $endpoint->withHost('*:80', new HttpHost(function (Context $context, HttpRequest $request) {
        return new RedirectResponse($request->getUri()->withScheme('https'));
    }));
    
    $endpoint = $endpoint->withMiddleware(new ResponseContentEncoder());
    
    yield $endpoint->listen($context);
});
