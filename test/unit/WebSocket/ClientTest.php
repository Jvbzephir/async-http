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

use KoolKode\Async\Http\Test\HttpTestClient;
use KoolKode\Async\Http\Test\HttpTestEndpoint;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http;
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
            $client = new Client(new HttpTestClient($socket));
            $conn = yield $client->connect('ws://localhost/');
            
            try {
                yield $conn->sendText('Hello WebSocket Server!');
                
                $this->assertEquals('Hello WebSocket Client! :)', yield $conn->receive());
            } finally {
                $conn->shutdown();
            }
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
            
            try {
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
        yield new SocketStreamTester(function (SocketStream $socket) {
            $client = new Client(new HttpTestClient($socket));
            
            $this->expectException(\RuntimeException::class);
            
            yield $client->connect('ws://localhost/');
        }, function (SocketStream $socket) {
            $server = new HttpTestEndpoint();
            
            yield $server->accept($socket, function (HttpRequest $request) {
                return new HttpResponse();
            });
        });
    }

    public function testMissingConnectionUpgrade()
    {
        yield new SocketStreamTester(function (SocketStream $socket) {
            $client = new Client(new HttpTestClient($socket));
            
            $this->expectException(\RuntimeException::class);
            
            yield $client->connect('ws://localhost/');
        }, function (SocketStream $socket) {
            $server = new HttpTestEndpoint();
            
            yield $server->accept($socket, function (HttpRequest $request) {
                return new HttpResponse(Http::SWITCHING_PROTOCOLS);
            });
        });
    }

    public function testMissingUpgradeHeader()
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
                    'Upgrade' => 'foo'
                ]);
            });
        });
    }

    public function testFailedAccept()
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
                    'Upgrade' => 'websocket'
                ]);
            });
        });
    }
}
