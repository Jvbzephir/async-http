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
$factory->registerLogger(new PipeLogHandler(PipeLogHandler::STDOUT), PipeLogHandler::INFO);

$factory->createContext()->run(function (Context $context) {
    $endpoint = new HttpEndpoint(new Http1Driver(), new Http2Driver());
    
    $endpoint = $endpoint->withDefaultHost(new HttpHost(function (Context $context, HttpRequest $request) {
        return new RedirectResponse($request->getUri()->withScheme('https'));
    }));
    
    $endpoint = $endpoint->withDefaultEncryptedHost(new HttpHost(function (Context $context, HttpRequest $request) {
        return new TextResponse('RECEIVED: ' . $request->getUri());
    }), AsyncTestCase::getLocalhostCertificateFile());
    
    $endpoint = $endpoint->withMiddleware(new ResponseContentEncoder());
    
    yield $endpoint->listen($context);
});
