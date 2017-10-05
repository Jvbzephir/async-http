<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Context;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Test\SocketTestHelper;

/**
 * @covers \KoolKode\Async\Http\Http1\Http1Driver
 */
class Http1DriverTest extends AsyncTestCase
{
    use SocketTestHelper;
    
    public function testDriverInterface()
    {
        $driver = new Http1Driver();
        
        $this->assertEquals(11, $driver->getPriority());
        $this->assertEquals((array) 'http/1.1', $driver->getProtocols());
        $this->assertTrue($driver->isSupported('http/1.1'));
        $this->assertTrue($driver->isSupported(''));
        $this->assertFalse($driver->isSupported('h2'));
    }

    public function testHead(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            
            yield $socket->write($context, implode("\r\n", [
                'HEAD /foo HTTP/1.0',
                'Content-Length: 0',
                'Connection: close',
                "\r\n"
            ]));
            
            $response = yield from $parser->parseResponse($context, $socket);
            $response = $response->withBody(new StreamBody($parser->parseBodyStream($response, $socket)));
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.0', $response->getProtocolVersion());
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals('', yield $response->getBody()->getContents($context));
        }, function (Context $context, Socket $socket) {
            $driver = new Http1Driver();
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request) {
                $this->assertEquals(Http::HEAD, $request->getMethod());
                $this->assertEquals('1.0', $request->getProtocolVersion());
                $this->assertEquals('/foo', $request->getRequestTarget());
                $this->assertEquals('', yield $request->getBody()->getContents($context));
                
                return new HttpResponse();
            });
        });
    }
}
