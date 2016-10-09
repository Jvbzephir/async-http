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

use KoolKode\Async\Http\Http1\Connector;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\HttpEndpoint
 * @covers \KoolKode\Async\Http\HttpServer
 */
class HttpEndpointTest extends AsyncTestCase
{
    public function testClient()
    {
        $endpoint = new HttpEndpoint();
        $server = yield $endpoint->listen();
        
        $this->assertTrue($server instanceof HttpServer);
        
        try {
            $factory = $server->createSocketFactory();
            
            $socket = yield $factory->createSocketStream();
            
            try {
                $request = new HttpRequest('http://' . $factory->getPeer() . '/');
                $response = yield (new Connector())->send($socket, $request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.1', $response->getProtocolVersion());
                $this->assertEquals(Http::OK, $response->getStatusCode());
                $this->assertEquals('OK', $response->getReasonPhrase());
                $this->assertEquals('KoolKode Async HTTP Server', $response->getHeaderLine('Server'));
                
                $this->assertEquals('Hello Test Client :)', yield $response->getBody()->getContents());
                
                yield new \KoolKode\Async\Pause(.1);
            } finally {
                $socket->close();
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
            $factory = $server->createSocketFactory();
            
            $socket = yield $factory->createSocketStream();
            
            try {
                $request = new HttpRequest('http://' . $factory->getPeer() . '/', Http::HEAD, [], '1.0');
                $response = yield (new Connector())->send($socket, $request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.0', $response->getProtocolVersion());
                $this->assertEquals(Http::OK, $response->getStatusCode());
                $this->assertEquals('OK', $response->getReasonPhrase());
                $this->assertEquals('KoolKode Async HTTP Server', $response->getHeaderLine('Server'));
                
                $this->assertEquals('', yield $response->getBody()->getContents());
            } finally {
                $socket->close();
            }
        } finally {
            $server->stop();
        }
    }
}
