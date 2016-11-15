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

use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Loop\LoopConfig;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Http\HttpDriverContext;

require_once __DIR__ . '/websocket.php';

$websocket = new ExampleEndpoint();

return function (HttpRequest $request) use ($websocket) {
    switch (trim($request->getRequestTarget(), '/')) {
        case 'websocket':
            return $websocket;
        case '':
            $html = yield new ReadContents(yield LoopConfig::currentFilesystem()->readStream(__DIR__ . '/index.html'));
            
            $peer = $request->getAttribute(HttpDriverContext::class)->getPeer();
            $scheme = ($request->getUri()->getScheme() === 'https') ? 'wss' : 'ws';
            $uri = $scheme . '://' . $request->getUri()->getHostWithPort() . '/websocket';
            
            $html = strtr($html, [
                '###URI###' => htmlspecialchars((string) $request->getUri(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                '###HOST###' => htmlspecialchars($peer, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                '###WEBSOCKET_URI###' => htmlspecialchars($uri, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            ]);
            
            $response = new HttpResponse(Http::OK, [
                'Content-Type' => 'text/html;charset="utf-8"'
            ]);
            
            return $response->withBody(new StringBody($html));
    }
    
    return new HttpResponse(Http::NOT_FOUND);
};
