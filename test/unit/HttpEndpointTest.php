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

use KoolKode\Async\Http\Http1\Client;
use KoolKode\Async\Http\Http1\Connector;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\HttpEndpoint
 * @covers \KoolKode\Async\Http\HttpServer
 */
class HttpEndpointTest extends AsyncTestCase
{
    public function provideKeepAlive()
    {
        yield [false];
        yield [true];
    }
    
    /**
     * @dataProvider provideKeepAlive
     */
    public function testConnect(bool $keepAlive)
    {
        $connector = new Connector();
        $connector->setKeepAliveSupported($keepAlive);
        
        $client = new Client($connector);
        
        try {
            $request = new HttpRequest('https://httpbin.org/user-agent');
            $response = yield $client->send($request);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals(Http::OK, $response->getStatusCode());
            
            $this->assertEquals([
                'user-agent' => 'KoolKode HTTP Client'
            ], json_decode(yield $response->getBody()->getContents(), true));
            
            $request = new HttpRequest('https://httpbin.org/user-agent');
            $response = yield $client->send($request);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals(Http::OK, $response->getStatusCode());
            
            $this->assertEquals([
                'user-agent' => 'KoolKode HTTP Client'
            ], json_decode(yield $response->getBody()->getContents(), true));
        } finally {
            $client->shutdown();
        }
    }
    
    public function testClient()
    {
        $endpoint = new HttpEndpoint();
        $server = yield $endpoint->listen();
        
        $this->assertTrue($server instanceof HttpServer);
        
        try {
            $client = new Client();
            
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
                $this->assertEquals('KoolKode HTTP Server', $response->getHeaderLine('Server'));
                
                $this->assertEquals($data, yield $response->getBody()->getContents());
                
                $request = new HttpRequest($server->getBaseUri());
                $response = yield $client->send($request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.1', $response->getProtocolVersion());
                $this->assertEquals(Http::OK, $response->getStatusCode());
                $this->assertEquals('OK', $response->getReasonPhrase());
                $this->assertEquals('KoolKode HTTP Server', $response->getHeaderLine('Server'));
                
                $this->assertEquals('Hello Test Client :)', yield $response->getBody()->getContents());
            } finally {
                $client->shutdown();
            }
        } finally {
            $server->stop();
        }
    }

    public function testHttp1HeadRequest()
    {
        $endpoint = new HttpEndpoint();
        $server = yield $endpoint->listen();
        
        $this->assertTrue($server instanceof HttpServer);
        
        try {
            $client = new Client();
            
            try {
                $request = new HttpRequest($server->getBaseUri(), Http::HEAD, [], '1.0');
                $response = yield $client->send($request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.0', $response->getProtocolVersion());
                $this->assertEquals(Http::OK, $response->getStatusCode());
                $this->assertEquals('OK', $response->getReasonPhrase());
                $this->assertEquals('KoolKode HTTP Server', $response->getHeaderLine('Server'));
                
                $this->assertEquals('', yield $response->getBody()->getContents());
            } finally {
                $client->shutdown();
            }
        } finally {
            $server->stop();
        }
    }
}
