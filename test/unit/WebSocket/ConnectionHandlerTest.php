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

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Http\Http1\Connector;
use KoolKode\Async\Http\Test\EndToEndTest;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Http\TestLogger;

/**
 * @covers \KoolKode\Async\Http\WebSocket\ConnectionHandler
 */
class ConnectionHandlerTest extends EndToEndTest
{
    public function getConnectors(): array
    {
        return [
            new Connector()
        ];
    }
    
    public function testDetectsUpgradeAvailability()
    {
        $conn = new ConnectionHandler();
        
        $this->assertFalse($conn->isUpgradeSupported('foo', new HttpRequest('/'), []));
        $this->assertFalse($conn->isUpgradeSupported('websocket', new HttpRequest('/'), []));
        $this->assertTrue($conn->isUpgradeSupported('websocket', new HttpRequest('/'), new class() extends Endpoint {}));
    }
    
    public function testRequiresValidEndpoint()
    {
        $conn = new ConnectionHandler();
        
        $this->expectException(\InvalidArgumentException::class);
        
        $conn->createUpgradeResponse(new HttpRequest('/'), []);
    }

    public function testUpgradeRequiresGetRequest()
    {
        $conn = new ConnectionHandler();
        
        try {
            $conn->createUpgradeResponse(new HttpRequest('/', Http::POST), new class() extends Endpoint {});
            
            $this->fail('Failed to assert HTTP status exception');
        } catch (StatusException $e) {
            $this->assertEquals(Http::METHOD_NOT_ALLOWED, $e->getCode());
            $this->assertEquals(Http::GET, implode(', ', $e->getHeaders()['allow']));
        }
    }
    
    public function testUpgradeRequiresKey()
    {
        $conn = new ConnectionHandler();
        
        try {
            $conn->createUpgradeResponse(new HttpRequest('/'), new class() extends Endpoint {});
            
            $this->fail('Failed to assert HTTP status exception');
        } catch (StatusException $e) {
            $this->assertEquals(Http::BAD_REQUEST, $e->getCode());
        }
    }

    public function testUpgradeRequiresVersion13()
    {
        $conn = new ConnectionHandler();
        
        try {
            $conn->createUpgradeResponse(new HttpRequest('/', Http::GET, [
                'Sec-Websocket-Key' => 'foo'
            ]), new class() extends Endpoint {});
            
            $this->fail('Failed to assert HTTP status exception');
        } catch (StatusException $e) {
            $this->assertEquals(Http::BAD_REQUEST, $e->getCode());
        }
    }

    public function testCanGenerateUpgradeResponse()
    {
        $conn = new ConnectionHandler();
        $conn->setDeflateSupported(true);
        
        $endpoint = new class() extends Endpoint {

            public function negotiateProtocol(array $protocols): string
            {
                return 'bar';
            }
        };
        
        $response = $conn->createUpgradeResponse(new HttpRequest('', Http::GET, [
            'Sec-WebSocket-Key' => 'foo',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Protocol' => [
                'foo',
                'bar'
            ],
            'Sec-WebSocket-Extensions' => 'permessage-deflate'
        ]), $endpoint);
        
        $this->assertEquals(Http::SWITCHING_PROTOCOLS, $response->getStatusCode());
        $this->assertEquals('websocket', $response->getHeaderLine('Upgrade'));
        $this->assertEquals('13', $response->getHeaderLine('Sec-WebSocket-Version'));
        $this->assertEquals(base64_encode(sha1('foo' . ConnectionHandler::GUID, true)), $response->getHeaderLine('Sec-WebSocket-Accept'));
        $this->assertEquals('bar', $response->getHeaderLine('Sec-WebSocket-Protocol'));
        $this->assertEquals(function_exists('inflate_init'), in_array('permessage-deflate', $response->getHeaderTokenValues('Sec-WebSocket-Extensions'), true));
        $this->assertSame($endpoint, $response->getAttribute(Endpoint::class));
    }
    
    public function testDetectsClientDoesNotSupportCompression()
    {
        $conn = new ConnectionHandler();
        $conn->setDeflateSupported(true);
        
        $response = $conn->createUpgradeResponse(new HttpRequest('/', Http::GET, [
            'Sec-WebSocket-Key' => 'foo',
            'Sec-WebSocket-Version' => '13'
        ]), new class() extends Endpoint {});
        
        $this->assertFalse($response->hasHeader('Sec-WebSocket-Extensions'));
    }

    public function testDetectsInvalidCompressionSettings()
    {
        $conn = new ConnectionHandler();
        $conn->setDeflateSupported(true);
        
        $response = $conn->createUpgradeResponse(new HttpRequest('/', Http::GET, [
            'Sec-WebSocket-Key' => 'foo',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Extensions' => 'permessage-deflate;client_max_window_bits=16'
        ]), new class() extends Endpoint {});
        
        $this->assertFalse($response->hasHeader('Sec-WebSocket-Extensions'));
    }
    
    public function testDetectsMissingEndpointAttribute()
    {
        $conn = new ConnectionHandler();
        
        $this->expectException(\InvalidArgumentException::class);
        
        yield from $conn->upgradeConnection(new SocketStream(tmpfile()), new HttpRequest('/'), new HttpResponse());
    }

    public function testCanUpgradeConnection()
    {
        $conn = new ConnectionHandler($logger = new TestLogger());
        
        $response = new HttpResponse(Http::SWITCHING_PROTOCOLS);
        $response = $response->withAttribute(Endpoint::class, new class() extends Endpoint {});
        
        list ($a, $b) = Socket::createPair();
        
        Socket::shutdown($b);
        
        $this->assertNull(yield from $conn->upgradeConnection(new SocketStream($a), new HttpRequest('/'), $response));
        
        $this->assertCount(2, $logger);
    }

    public function provideDeflateSettings()
    {
        yield [false];
        yield [true];
    }
    
    /**
     * @dataProvider provideDeflateSettings
     */
    public function testCanHandleTextMessages(bool $deflate)
    {
        $this->httpServer->setAction(function () {
            $endpoint = new TestEndpoint();
            
            $endpoint->handleTextMessage(function (Connection $conn, string $message) {
                $this->assertEquals('Hello Server!', $message);
                
                $conn->sendText('Hello Client!');
            });
            
            return $endpoint;
        });
        
        $client = new Client($this->httpClient);
        $client->setDeflateSupported($deflate);
        
        $conn = yield $client->connect($this->getBaseUri());
        
        $this->assertTrue($conn instanceof Connection);
        
        try {
            yield $conn->sendText('Hello Server!');
            $this->assertEquals('Hello Client!', yield $conn->receive());
        } finally {
            $conn->shutdown();
        }
    }

    /**
     * @dataProvider provideDeflateSettings
     */
    public function testCanHandleBinaryMessages(bool $deflate)
    {
        $this->httpServer->setAction(function () {
            $endpoint = new TestEndpoint();
            
            $endpoint->handleBinaryMessage(function (Connection $conn, ReadableStream $message) {
                $this->assertEquals('Hello Server Stream!', yield new ReadContents($message));
                
                $conn->sendBinary(new ReadableMemoryStream('Hello Client Stream!'));
            });
            
            return $endpoint;
        });
        
        $client = new Client($this->httpClient);
        $client->setDeflateSupported($deflate);
        
        $conn = yield $client->connect($this->getBaseUri());
        
        $this->assertTrue($conn instanceof Connection);
        
        try {
            yield $conn->sendBinary(new ReadableMemoryStream('Hello Server Stream!'));
            $this->assertEquals('Hello Client Stream!', yield new ReadContents(yield $conn->receive()));
        } finally {
            $conn->shutdown();
        }
    }
}
