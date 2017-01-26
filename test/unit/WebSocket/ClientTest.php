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

use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\Test\EndToEndTest;
use KoolKode\Async\Http\Test\HttpMockClient;

/**
 * @covers \KoolKode\Async\Http\WebSocket\Client
 */
class ClientTest extends EndToEndTest
{
    public function testBasicEcho()
    {
        $this->httpServer->setAction(function (HttpRequest $request) {
            $endpoint = new TestEndpoint();
            
            $endpoint->handleTextMessage(function (Connection $conn, string $text) {
                $this->assertEquals('Hello WebSocket Server!', $text);
                
                $conn->sendText('Hello WebSocket Client! :)');
            });
            
            return $endpoint;
        });
        
        $client = new Client($this->httpClient);
        $conn = yield $client->connect('ws://localhost/');
        
        $this->assertTrue($conn instanceof Connection);
        
        try {
            $this->assertFalse($conn->isCompressionEnabled());
            
            yield $conn->sendText('Hello WebSocket Server!');
            
            $this->assertEquals('Hello WebSocket Client! :)', yield $conn->receive());
        } finally {
            $conn->shutdown();
        }
    }
    
    public function testCompressedEcho()
    {
        $this->httpServer->setAction(function (HttpRequest $request) {
            $endpoint = new TestEndpoint();
            
            $endpoint->handleTextMessage(function (Connection $conn, string $text) {
                $this->assertEquals('Hello WebSocket Server!', $text);
                
                $conn->sendText('Hello WebSocket Client! :)');
            });
            
            return $endpoint;
        });
        
        $client = new Client($this->httpClient);
        $client->setDeflateSupported(true);
        
        $conn = yield $client->connect('ws://localhost/');
        
        $this->assertTrue($conn instanceof Connection);
        
        try {
            $this->assertEquals(function_exists('inflate_init'), $conn->isCompressionEnabled());
            
            yield $conn->sendText('Hello WebSocket Server!');
            
            $this->assertEquals('Hello WebSocket Client! :)', yield $conn->receive());
        } finally {
            $conn->shutdown();
        }
    }
    
    public function testAppProtocolNegotiation()
    {
        $this->httpServer->setAction(function (HttpRequest $request) {
            return new class() extends Endpoint {

                public function negotiateProtocol(array $protocols): string
                {
                    return 'bar';
                }
            };
        });
        
        $client = new Client($this->httpClient);
        $conn = yield $client->connect('ws://localhost/', [
            'foo',
            'bar'
        ]);
        
        $this->assertTrue($conn instanceof Connection);
        
        try {
            $this->assertEquals('bar', $conn->getProtocol());
        } finally {
            $conn->shutdown();
        }
    }

    public function testAppProtocolValidationError()
    {
        $this->httpServer->setAction(function (HttpRequest $request) {
            return new class() extends Endpoint {

                public function negotiateProtocol(array $protocols): string
                {
                    return 'bar';
                }
            };
        });
        
        $client = new Client($this->httpClient);
        
        $this->expectException(\OutOfRangeException::class);
        
        yield $client->connect('ws://localhost/', [
            'foo'
        ]);
    }

    public function testUnexpectedResponseStatusCode()
    {
        $client = new Client(new HttpMockClient([
            new HttpResponse()
        ]));
        
        $this->expectException(\RuntimeException::class);
        
        yield $client->connect('ws://localhost/');
    }

    public function testMissingConnectionUpgrade()
    {
        $client = new Client(new HttpMockClient([
            new HttpResponse(Http::SWITCHING_PROTOCOLS)
        ]));
        
        $this->expectException(\RuntimeException::class);
        
        yield $client->connect('ws://localhost/');
    }

    public function testInvalidUpgradeHeader()
    {
        $client = new Client(new HttpMockClient([
            new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                'Connection' => 'upgrade',
                'Upgrade' => 'foo'
            ])
        ]));
        
        $this->expectException(\RuntimeException::class);
        
        yield $client->connect('ws://localhost/');
    }

    public function testFailedAccept()
    {
        $client = new Client(new HttpMockClient([
            new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                'Connection' => 'upgrade',
                'Upgrade' => 'websocket'
            ])
        ]));
        
        $this->expectException(\RuntimeException::class);
        
        yield $client->connect('ws://localhost/');
    }

    public function testInvalidCompressionSettingsDisableCompression()
    {
        $this->httpServer->setAction(function (HttpRequest $request) {
            return new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                'Connection' => 'upgrade',
                'Upgrade' => 'websocket',
                'Sec-WebSocket-Accept' => \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . Client::GUID, true)),
                'Sec-WebSocket-Extensions' => 'permessage-deflate;client_max_window_bits=16'
            ]);
        });
        
        $client = new Client($this->httpClient);
        $client->setDeflateSupported(true);
        
        $conn = yield $client->connect('ws://localhost/');
        
        $this->assertTrue($conn instanceof Connection);
        
        try {
            $this->assertFalse($conn->isCompressionEnabled());
        } finally {
            $conn->shutdown();
        }
    }

    public function testUnexpectedCompressionExtension()
    {
        $this->httpServer->setAction(function (HttpRequest $request) {
            return new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                'Connection' => 'upgrade',
                'Upgrade' => 'websocket',
                'Sec-WebSocket-Accept' => \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . Client::GUID, true)),
                'Sec-WebSocket-Extensions' => 'permessage-deflate'
            ]);
        });
        
        $client = new Client($this->httpClient);
        
        $this->expectException(\RuntimeException::class);
        
        yield $client->connect('ws://localhost/');
    }

    public function testDetectsMissingSocketStreamAttribute()
    {
        $client = new Client();
        
        $this->expectException(\RuntimeException::class);
        
        call_user_func(\Closure::bind(function () {
            $this->establishConnection('foo', new HttpResponse());
        }, $client, $client));
    }
}
