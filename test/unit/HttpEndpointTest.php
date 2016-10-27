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

use KoolKode\Async\Http\Http1\Connector as Http1Connector;
use KoolKode\Async\Http\Http2\Connector as Http2Connector;
use KoolKode\Async\Http\Middleware\ContentDecoder;
use KoolKode\Async\Http\Middleware\ContentEncoder;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @coversNothing
 */
class HttpEndpointTest extends AsyncTestCase
{
    public function testClient()
    {
        $endpoint = new HttpEndpoint();
        $endpoint->addMiddleware(new ContentEncoder(), -10000);
        
        $server = yield $endpoint->listen(function (HttpRequest $request) {
            $response = new HttpResponse();
            
            $this->assertEquals(sprintf('PHP/%s', PHP_VERSION), $request->getHeaderLine('User-Agent'));
            $this->assertEquals('Filtered Request', $request->getHeaderLine('Middleware-Info'));
            
            if ($request->getMethod() === Http::POST) {
                $response = $response->withHeader('Content-Type', 'application/json');
                $response = $response->withBody(new StringBody(yield $request->getBody()->getContents()));
            } else {
                $response = $response->withBody(new StringBody('Hello Test Client :)'));
            }
            
            return $response;
        });
        
        $this->assertTrue($server instanceof HttpServer);
        
        try {
            $client = new HttpClient();
            $client->addMiddleware(new ContentDecoder(), -10000);
            
            $client->addMiddleware(function (HttpRequest $request, NextMiddleware $next) {
                $request = $request->withHeader('Middleware-Info', 'Filtered Request');
                
                $response = yield from $next($request);
                $response = $response->withHeader('Middleware-Info', 'Filtered Response');
                
                return $response;
            });
            
            try {
                $data = json_encode([
                    'payload' => 'test'
                ], JSON_UNESCAPED_SLASHES);
                
                $request = new HttpRequest($server->getBaseUri(), Http::POST);
                $request = $request->withHeader('Content-Type', 'application/json');
                $request = $request->withBody(new StringBody($data));
                
                $response = yield $client->send($request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.1', $response->getProtocolVersion());
                $this->assertEquals(Http::OK, $response->getStatusCode());
                $this->assertEquals('OK', $response->getReasonPhrase());
                $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
                $this->assertEquals('Filtered Response', $response->getHeaderLine('Middleware-Info'));
                
                $this->assertEquals($data, yield $response->getBody()->getContents());
                
                $request = new HttpRequest($server->getBaseUri());
                $response = yield $client->send($request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.1', $response->getProtocolVersion());
                $this->assertEquals(Http::OK, $response->getStatusCode());
                $this->assertEquals('OK', $response->getReasonPhrase());
                $this->assertEquals('Filtered Response', $response->getHeaderLine('Middleware-Info'));
                
                $this->assertEquals('Hello Test Client :)', yield $response->getBody()->getContents());
            } finally {
                yield $client->shutdown();
            }
        } finally {
            $server->stop();
        }
    }

    public function testHttp1HeadRequest()
    {
        $endpoint = new HttpEndpoint();
        
        $server = yield $endpoint->listen(function (HttpRequest $request) {
            return new HttpResponse();
        });
        
        $this->assertTrue($server instanceof HttpServer);
        
        try {
            $client = new HttpClient();
            
            try {
                $request = new HttpRequest($server->getBaseUri(), Http::HEAD, [], '1.0');
                $response = yield $client->send($request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.0', $response->getProtocolVersion());
                $this->assertEquals(Http::OK, $response->getStatusCode());
                $this->assertEquals('OK', $response->getReasonPhrase());
                
                $this->assertEquals('', yield $response->getBody()->getContents());
            } finally {
                yield $client->shutdown();
            }
        } finally {
            $server->stop();
        }
    }
    
    public function testHttp2Client()
    {
        if (!Socket::isAlpnSupported()) {
            return $this->markTestSkipped('Test requires SSL ALPN support');
        }
        
        $client = new HttpClient(new Http2Connector(), new Http1Connector());
        
        $client->addMiddleware(function (HttpRequest $request, NextMiddleware $next) {
            $response = yield from $next($request);
            $response = $response->withHeader('Middleware-Info', 'Filtered Response');
            
            return $response;
        });
        
        try {
            $request = new HttpRequest('https://http2.golang.org/ECHO', Http::PUT);
            $request = $request->withBody(new StringBody('Hello World :)'));
            
            $response = yield $client->send($request);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals('Filtered Response', $response->getHeaderLine('Middleware-Info'));
            
            $this->assertEquals('HELLO WORLD :)', yield $response->getBody()->getContents());
        } finally {
            $client->shutdown();
        }
    }
}
