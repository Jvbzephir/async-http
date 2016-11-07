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
use KoolKode\Async\Http\TestLogger;
use KoolKode\Async\Http\Test\HttpMockClient;
use KoolKode\Async\Http\Test\HttpTestClient;
use KoolKode\Async\Http\Test\HttpTestEndpoint;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Test\SocketStreamTester;

/**
 * @covers \KoolKode\Async\Http\WebSocket\Client
 */
class ClientTest extends AsyncTestCase
{
    public function testBasicEcho()
    {
        yield new SocketStreamTester(function (SocketStream $socket) {
            $logger = new TestLogger();
            
            $client = new Client(new HttpTestClient($socket), $logger);
            $conn = yield $client->connect('ws://localhost/');
            
            $this->assertTrue($conn instanceof Connection);
            
            try {
                $this->assertFalse($conn->isCompressionEnabled());
                
                yield $conn->sendText('Hello WebSocket Server!');
                
                $this->assertEquals('Hello WebSocket Client! :)', yield $conn->receive());
            } finally {
                $conn->shutdown();
            }
            
            $this->assertCount(1, $logger);
        }, function (SocketStream $socket) {
            $server = new HttpTestEndpoint();
            $server->addUpgradeResultHandler(new ConnectionHandler());
            
            yield $server->accept($socket, function (HttpRequest $request) {
                $endpoint = new TestEndpoint();
                $endpoint->handleTextMessage(function (Connection $conn, string $text) {
                    $this->assertEquals('Hello WebSocket Server!', $text);
                    
                    $conn->sendText('Hello WebSocket Client! :)');
                });
                
                return $endpoint;
            });
        });
    }
    
    public function testCompressedEcho()
    {
        yield new SocketStreamTester(function (SocketStream $socket) {
            $client = new Client(new HttpTestClient($socket));
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
        }, function (SocketStream $socket) {
            $ws = new ConnectionHandler();
            $ws->setDeflateSupported(true);
            
            $server = new HttpTestEndpoint();
            $server->addUpgradeResultHandler($ws);
            
            yield $server->accept($socket, function (HttpRequest $request) {
                $endpoint = new TestEndpoint();
                $endpoint->handleTextMessage(function (Connection $conn, string $text) {
                    $this->assertEquals('Hello WebSocket Server!', $text);
                    
                    $conn->sendText('Hello WebSocket Client! :)');
                });
                
                return $endpoint;
            });
        });
    }
    
    public function testAppProtocolNegotiation()
    {
        yield new SocketStreamTester(function (SocketStream $socket) {
            $client = new Client(new HttpTestClient($socket));
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
        }, function (SocketStream $socket) {
            $server = new HttpTestEndpoint();
            $server->addUpgradeResultHandler(new ConnectionHandler());
            
            yield $server->accept($socket, function (HttpRequest $request) {
                return new class() extends Endpoint {

                    public function negotiateProtocol(array $protocols): string
                    {
                        return 'bar';
                    }
                };
            });
        });
    }
    
    public function testAppProtocolValidationError()
    {
        yield new SocketStreamTester(function (SocketStream $socket) {
            $client = new Client(new HttpTestClient($socket));
            
            $this->expectException(\OutOfRangeException::class);
            
            yield $client->connect('ws://localhost/', [
                'foo'
            ]);
        }, function (SocketStream $socket) {
            $server = new HttpTestEndpoint();
            $server->addUpgradeResultHandler(new ConnectionHandler());
            
            yield $server->accept($socket, function (HttpRequest $request) {
                return new class() extends Endpoint {

                    public function negotiateProtocol(array $protocols): string
                    {
                        return 'bar';
                    }
                };
            });
        });
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
        yield new SocketStreamTester(function (SocketStream $socket) {
            $client = new Client(new HttpTestClient($socket));
            $client->setDeflateSupported(true);
            
            $conn = yield $client->connect('ws://localhost/');
            
            $this->assertTrue($conn instanceof Connection);
            
            try {
                $this->assertFalse($conn->isCompressionEnabled());
            } finally {
                $conn->shutdown();
            }
        }, function (SocketStream $socket) {
            $server = new HttpTestEndpoint();
    
            yield $server->accept($socket, function (HttpRequest $request) {
                return new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                    'Connection' => 'upgrade',
                    'Upgrade' => 'websocket',
                    'Sec-WebSocket-Accept' => \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . Client::GUID, true)),
                    'Sec-WebSocket-Extensions' => 'permessage-deflate;client_max_window_bits=16'
                ]);
            });
        });
    }
    
    public function testUnexpectedCompressionExtension()
    {
        yield new SocketStreamTester(function (SocketStream $socket) {
            $client = new Client(new HttpTestClient($socket));
            
            $this->expectException(\RuntimeException::class);
            
            yield $client->connect('ws://localhost/');
        }, function (SocketStream $socket) {
            $server = new HttpTestEndpoint();
            
            yield $server->accept($socket, function (HttpRequest $request) {
                return new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                    'Connection' => 'upgrade',
                    'Upgrade' => 'websocket',
                    'Sec-WebSocket-Accept' => \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . Client::GUID, true)),
                    'Sec-WebSocket-Extensions' => 'permessage-deflate'
                ]);
            });
        });
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
