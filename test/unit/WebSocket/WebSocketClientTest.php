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
use KoolKode\Async\Success;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpClient;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http1\Upgrade;
use KoolKode\Async\Http\Http1\UpgradeFailedException;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\WebSocket\WebSocketClient
 */
class WebSocketClientTest extends AsyncTestCase
{
    public function provideValidUris()
    {
        yield ['/foo', '/foo'];
        yield ['http://test.me/', 'http://test.me/'];
        yield ['ws://test.me/', 'http://test.me/'];
        yield ['https://test.me/', 'https://test.me/'];
        yield ['wss://test.me/', 'https://test.me/'];
    }

    /**
     * @dataProvider provideValidUris
     */
    public function testDetectsValidUris(Context $context, string $uri, string $expected)
    {
        $client = new class($this->createMock(HttpClient::class)) extends WebSocketClient {

            protected function connectTask(Context $context, string $uri, array $protocols): \Generator
            {
                if (false) {
                    yield null;
                }
                
                return $uri;
            }
        };
        
        $this->assertEquals($expected, yield $client->connect($context, $uri));
    }

    public function testRejectsInvalidUriScheme()
    {
        $client = new WebSocketClient($this->createMock(HttpClient::class));
        
        $this->expectException(\InvalidArgumentException::class);
        
        $client->connect(new Context($this->createLoop()), 'foo://bar');
    }

    public function testDetectsWrongStatusCode(Context $context)
    {
        $mock = $this->createMock(HttpClient::class);
        $mock->expects($this->once())->method('send')->willReturnCallback(function (Context $context, HttpRequest $request) {
            return new Success($context, new HttpResponse(Http::NOT_FOUND));
        });
        
        $client = new WebSocketClient($mock);
        
        $this->expectException(UpgradeFailedException::class);
        
        yield $client->connect($context, '/websocket');
    }

    public function testDetectsMissingUpgradeAttribute(Context $context)
    {
        $mock = $this->createMock(HttpClient::class);
        $mock->expects($this->once())->method('send')->willReturnCallback(function (Context $context, HttpRequest $request) {
            return new Success($context, new HttpResponse(Http::SWITCHING_PROTOCOLS));
        });
        
        $client = new WebSocketClient($mock);
        
        $this->expectException(UpgradeFailedException::class);
        
        yield $client->connect($context, '/websocket');
    }

    public function testDetectsMissingUpgradeProtocol(Context $context)
    {
        $mock = $this->createMock(HttpClient::class);
        $mock->expects($this->once())->method('send')->willReturnCallback(function (Context $context, HttpRequest $request) {
            $response = new HttpResponse(Http::SWITCHING_PROTOCOLS);
            $response = $response->withAttribute(Upgrade::class, new Upgrade($this->createMock(DuplexStream::class), 'foo'));
            
            return new Success($context, $response);
        });
        
        $client = new WebSocketClient($mock);
        
        $this->expectException(UpgradeFailedException::class);
        
        yield $client->connect($context, '/websocket');
    }
    
    public function testDetectsInvalidAccept(Context $context)
    {
        $mock = $this->createMock(HttpClient::class);
        $mock->expects($this->once())->method('send')->willReturnCallback(function (Context $context, HttpRequest $request) {
            $response = new HttpResponse(Http::SWITCHING_PROTOCOLS);
            $response = $response->withAttribute(Upgrade::class, new Upgrade($this->createMock(DuplexStream::class), 'websocket'));
            
            return new Success($context, $response);
        });
        
        $client = new WebSocketClient($mock);
        
        $this->expectException(UpgradeFailedException::class);
        
        yield $client->connect($context, '/websocket');
    }

    public function testValidConnect(Context $context)
    {
        $mock = $this->createMock(HttpClient::class);
        $mock->expects($this->once())->method('send')->willReturnCallback(function (Context $context, HttpRequest $request) {
            $accept = \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . Connection::GUID, true));
            
            $response = new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                'Sec-WebSocket-Accept' => $accept
            ]);
            $response = $response->withAttribute(Upgrade::class, new Upgrade($this->createMock(DuplexStream::class), 'websocket'));
            
            return new Success($context, $response);
        });
        
        $client = new WebSocketClient($mock);
        
        $this->assertInstanceOf(Connection::class, yield $client->connect($context, '/websocket'));
    }
    
    public function testCanNegotiateProtocol(Context $context)
    {
        $mock = $this->createMock(HttpClient::class);
        $mock->expects($this->once())->method('send')->willReturnCallback(function (Context $context, HttpRequest $request) {
            $accept = \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . Connection::GUID, true));
            
            $response = new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                'Sec-WebSocket-Accept' => $accept,
                'Sec-WebSocket-Protocol' => 'bar'
            ]);
            $response = $response->withAttribute(Upgrade::class, new Upgrade($this->createMock(DuplexStream::class), 'websocket'));
            
            return new Success($context, $response);
        });
        
        $client = new WebSocketClient($mock);
        
        $conn = yield $client->connect($context, '/websocket', [
            'foo',
            'bar'
        ]);
        
        $this->assertEquals('bar', $conn->getProtocol());
    }
    
    public function testDetectsInvalidNegotiatedProtocol(Context $context)
    {
        $mock = $this->createMock(HttpClient::class);
        $mock->expects($this->once())->method('send')->willReturnCallback(function (Context $context, HttpRequest $request) {
            $accept = \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . Connection::GUID, true));
            
            $response = new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                'Sec-WebSocket-Accept' => $accept,
                'Sec-WebSocket-Protocol' => 'baz'
            ]);
            $response = $response->withAttribute(Upgrade::class, new Upgrade($this->createMock(DuplexStream::class), 'websocket'));
            
            return new Success($context, $response);
        });
        
        $client = new WebSocketClient($mock);
        
        $this->expectException(UpgradeFailedException::class);
        
        yield $client->connect($context, '/websocket', (array) 'foo');
    }

    public function testDetectsInvalidDeflateUsageByServer(Context $context)
    {
        $mock = $this->createMock(HttpClient::class);
        $mock->expects($this->once())->method('send')->willReturnCallback(function (Context $context, HttpRequest $request) {
            $accept = \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . Connection::GUID, true));
            
            $response = new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                'Sec-WebSocket-Accept' => $accept
            ]);
            $response = $response->withAttribute(Upgrade::class, new Upgrade($this->createMock(DuplexStream::class), 'websocket'));
            
            return new Success($context, $response);
        });
        
        $client = new class($mock, false) extends WebSocketClient {

            protected function negotiatePerMessageDeflate(HttpResponse $response): ?PerMessageDeflate
            {
                return new PerMessageDeflate(true, true);
            }
        };
        
        $this->expectException(UpgradeFailedException::class);
        
        yield $client->connect($context, '/websocket');
    }

    public function testCanEnablePerMessageDeflate()
    {
        $client = new class($this->createMock(HttpClient::class)) extends WebSocketClient {

            protected $zlib = true;

            public function negotiatePerMessageDeflate(HttpResponse $response): ?PerMessageDeflate
            {
                return parent::negotiatePerMessageDeflate($response);
            }
        };
        
        $deflate = $client->negotiatePerMessageDeflate(new HttpResponse(Http::SWITCHING_PROTOCOLS, [
            'Sec-WebSocket-Extensions' => 'permessage-deflate'
        ]));
        
        $this->assertInstanceOf(PerMessageDeflate::class, $deflate);
    }

    public function testDropsInvalidDeflate()
    {
        $client = new class($this->createMock(HttpClient::class)) extends WebSocketClient {

            protected $zlib = true;

            public function negotiatePerMessageDeflate(HttpResponse $response): ?PerMessageDeflate
            {
                return parent::negotiatePerMessageDeflate($response);
            }
        };
        
        $deflate = $client->negotiatePerMessageDeflate(new HttpResponse(Http::SWITCHING_PROTOCOLS, [
            'Sec-WebSocket-Extensions' => 'permessage-deflate;client_max_window_bits=7'
        ]));
        
        $this->assertNull($deflate);
    }
}
