<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\WebSocket;

use KoolKode\Async\Context;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Test\SocketTestHelper;

/**
 * @covers \KoolKode\Async\Http\WebSocket\WebSocketServer
 */
class WebSocketServerTest extends AsyncTestCase
{
    use SocketTestHelper;
    
    public function testCheckUpgradeSupported()
    {
        $server = new WebSocketServer();
        
        $this->assertFalse($server->isUpgradeSupported('foo', new HttpRequest('/'), null));
        $this->assertFalse($server->isUpgradeSupported('websocket', new HttpRequest('/'), null));
        $this->assertTrue($server->isUpgradeSupported('websocket', new HttpRequest('/'), $this->createMock(WebSocketEndpoint::class)));
    }

    public function testValidatesEndpointInUpgradeResponse()
    {
        $server = new WebSocketServer();
        
        $this->expectException(\InvalidArgumentException::class);
        
        $server->createUpgradeResponse(new HttpRequest('/'), null);
    }

    public function testValidatesHttpRequestMethod()
    {
        $server = new WebSocketServer();
        
        $this->expectException(StatusException::class);
        $this->expectExceptionCode(Http::METHOD_NOT_ALLOWED);
        
        $server->createUpgradeResponse(new HttpRequest('/', Http::POST), $this->createMock(WebSocketEndpoint::class));
    }
    
    public function testValidatesWebSocketKey()
    {
        $server = new WebSocketServer();
        
        $this->expectException(StatusException::class);
        $this->expectExceptionCode(Http::BAD_REQUEST);
        
        $server->createUpgradeResponse(new HttpRequest('/'), $this->createMock(WebSocketEndpoint::class));
    }
    
    public function testValidatesWebSocketVersion()
    {
        $server = new WebSocketServer();
        
        $this->expectException(StatusException::class);
        $this->expectExceptionCode(Http::BAD_REQUEST);
        
        $server->createUpgradeResponse(new HttpRequest('/', Http::GET, [
            'Sec-WebSocket-Key' => '123'
        ]), $this->createMock(WebSocketEndpoint::class));
    }

    public function testCanCreateUpgradeResponse()
    {
        $server = new WebSocketServer(true);
        
        $endpoint = $this->createMock(WebSocketEndpoint::class);
        $endpoint->expects($this->once())->method('onHandshake')->willReturnArgument(1);
        
        $response = $server->createUpgradeResponse(new HttpRequest('/', Http::GET, [
            'Sec-WebSocket-Key' => '123',
            'Sec-WebSocket-Version' => '13'
        ]), $endpoint);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals('1.1', $response->getProtocolVersion());
        $this->assertEquals(Http::SWITCHING_PROTOCOLS, $response->getStatusCode());
        $this->assertEquals('websocket', $response->getHeaderLine('Upgrade'));
        $this->assertEquals('13', $response->getHeaderLine('Sec-WebSocket-Version'));
        $this->assertEquals(\base64_encode(\sha1('123' . Connection::GUID, true)), $response->getHeaderLine('Sec-WebSocket-Accept'));
        
        $this->assertFalse($response->hasHeader('Sec-WebSocket-Protocol'));
        $this->assertFalse($response->hasHeader('Sec-WebSocket-Extensions'));
    }

    public function testCanNegotiateProtocol()
    {
        $server = new WebSocketServer();
        
        $endpoint = $this->createMock(WebSocketEndpoint::class);
        $endpoint->expects($this->once())->method('onHandshake')->willReturnArgument(1);
        $endpoint->expects($this->once())->method('negotiateProtocol')->willReturn('bar');
        
        $response = $server->createUpgradeResponse(new HttpRequest('/', Http::GET, [
            'Sec-WebSocket-Key' => '123',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Protocol' => 'foo, bar'
        ]), $endpoint);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::SWITCHING_PROTOCOLS, $response->getStatusCode());
        $this->assertEquals('bar', $response->getHeaderLine('Sec-WebSocket-Protocol'));
    }

    public function testCanEnableCompression()
    {
        $server = new class(true) extends WebSocketServer {

            protected $zlib = true;
        };
        
        $endpoint = $this->createMock(WebSocketEndpoint::class);
        $endpoint->expects($this->once())->method('onHandshake')->willReturnArgument(1);
        
        $response = $server->createUpgradeResponse(new HttpRequest('/', Http::GET, [
            'Sec-WebSocket-Key' => '123',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Extensions' => 'permessage-deflate'
        ]), $endpoint);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::SWITCHING_PROTOCOLS, $response->getStatusCode());
        $this->assertEquals('permessage-deflate', $response->getHeaderTokenValues('Sec-WebSocket-Extensions')[0]);
        $this->assertInstanceOf(PerMessageDeflate::class, $response->getAttribute(PerMessageDeflate::class));
    }

    public function testCannotEnableCompressionWithInvalidParams()
    {
        $server = new class(true) extends WebSocketServer {

            protected $zlib = true;
        };
        
        $endpoint = $this->createMock(WebSocketEndpoint::class);
        $endpoint->expects($this->once())->method('onHandshake')->willReturnArgument(1);
        
        $response = $server->createUpgradeResponse(new HttpRequest('/', Http::GET, [
            'Sec-WebSocket-Key' => '123',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Extensions' => 'permessage-deflate;client_max_window_bits=7'
        ]), $endpoint);
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::SWITCHING_PROTOCOLS, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Sec-WebSocket-Extensions'));
        $this->assertNull($response->getAttribute(PerMessageDeflate::class));
    }
    
    public function testCannotUpgradeConnectionWithoutEndpoint(Context $context)
    {
        $server = new WebSocketServer();
        $request = new HttpRequest('/');
        $response = new HttpResponse();
        
        $this->expectException(\InvalidArgumentException::class);
        
        yield from $server->upgradeConnection($context, $this->createMock(DuplexStream::class), $request, $response);
    }

    public function testCanUpgradeConnection(Context $context)
    {
        $endpoint = new class() extends WebSocketEndpoint {

            public $opened = false;

            public $closed = false;

            public function onOpen(Context $context, Connection $conn)
            {
                yield null;
                
                $this->opened = true;
            }

            public function onClose(Context $context, Connection $conn, ?\Throwable $e = null)
            {
                yield null;
                
                $this->closed = true;
            }

            public function onTextMessage(Context $context, Connection $conn, string $message)
            {
                yield $conn->sendText($context, 'Hello Client :)');
            }

            public function onBinaryMessage(Context $context, Connection $conn, ReadableStream $message)
            {
                yield $conn->sendBinary($context, $message);
            }
        };
        
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $conn = new Connection($context, true, new FramedStream($socket, $socket, true));
            
            yield $conn->sendText($context, 'Hello Server');
            $this->assertEquals('Hello Client :)', yield $conn->receive($context));
            
            yield $conn->sendBinary($context, new ReadableMemoryStream('Hello again...'));
            
            $message = yield $conn->receive($context);
            $this->assertInstanceOf(ReadableStream::class, $message);
            $this->assertEquals('Hello again...', yield $message->readBuffer($context, 1000, false));
            
            $conn->close();
        }, function (Context $context, Socket $socket) use ($endpoint) {
            $server = new WebSocketServer();
            
            $response = new HttpResponse();
            $response = $response->withAttribute(WebSocketEndpoint::class, $endpoint);
            
            try {
                yield from $server->upgradeConnection($context, $socket, new HttpRequest('/'), $response);
            } catch (StreamClosedException $e) {
                // Expected after last chunk...
            }
        });
    }
}
