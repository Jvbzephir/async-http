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
use KoolKode\Async\Http\ClientSettings;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Test\SocketTestHelper;

/**
 * @covers \KoolKode\Async\Http\Http1\Http1Connector
 * @covers \KoolKode\Async\Http\Http1\Upgrade
 */
class Http1ConnectorTest extends AsyncTestCase
{
    use SocketTestHelper;
    
    public function testConnectorInterface(Context $context)
    {
        $manager = $this->createMock(ConnectionManager::class);
        $manager->expects($this->once())->method('isConnected')->with('foo')->willReturn(true);
        
        $connector = new Http1Connector($manager);
        
        $this->assertEquals(11, $connector->getPriority());
        $this->assertTrue($connector->isRequestSupported(new HttpRequest('/')));
        $this->assertTrue(yield $connector->isConnected($context, 'foo'));
        $this->assertEquals((array) 'http/1.1', $connector->getProtocols());
        
        $this->assertTrue($connector->isSupported('http/1.1'));
        $this->assertTrue($connector->isSupported(''));
        $this->assertFalse($connector->isSupported('h2'));
    }

    public function testHttp10HeadRequest(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            $conn = $conn->withKeepAlive(false);
            
            $request = new HttpRequest('http://test.me/', Http::HEAD, [
                'Connection' => 'test',
                'Foo' => 'bar'
            ], null, '1.0');
            
            $response = yield $conn->send($context, $request, $socket);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.0', $response->getProtocolVersion());
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals('OK', $response->getReasonPhrase());
            
            $this->assertEquals('close', $response->getHeaderLine('Connection'));
            $this->assertEquals('baz', $response->getHeaderLine('Foo'));
            $this->assertEquals('', yield $response->getBody()->getContents($context));
        }, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::HEAD, $request->getMethod());
            $this->assertEquals('http://test.me/', (string) $request->getUri());
            $this->assertEquals('/', $request->getRequestTarget());
            $this->assertEquals('1.0', $request->getProtocolVersion());
            
            $this->assertTrue($request->hasHeader('Date'));
            $this->assertEquals('0', $request->getHeaderLine('Content-Length'));
            $this->assertEquals('bar', $request->getHeaderLine('Foo'));
            $this->assertEquals('close, test', $request->getHeaderLine('Connection'));
            $this->assertEquals('test.me', $request->getHeaderLine('Host'));
            
            yield $socket->write($context, implode("\r\n", [
                'HTTP/1.0 200 OK',
                'Connection: close',
                'Foo: baz'
            ]) . "\r\n\r\n");
        });
    }

    public function testHttpRequestAndResponseWithBody(Context $context)
    {
        $message = '{"message":"Hello World :)"}';
        
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) use ($message) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            $conn = $conn->withKeepAlive(false);
            
            $request = new HttpRequest('http://test.me/form', Http::POST, [
                'Content-Type' => 'application/json'
            ], new StringBody($message));
            
            $response = yield $conn->send($context, $request, $socket);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::CREATED, $response->getStatusCode());
            $this->assertEquals('Form posted', $response->getReasonPhrase());
            
            $this->assertEquals('close', $response->getHeaderLine('Connection'));
            $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
            $this->assertEquals($message, yield $response->getBody()->getContents($context));
        }, function (Context $context, Socket $socket) use ($message) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::POST, $request->getMethod());
            $this->assertEquals('http://test.me/form', (string) $request->getUri());
            $this->assertEquals('/form', $request->getRequestTarget());
            $this->assertEquals('1.1', $request->getProtocolVersion());
            
            $this->assertTrue($request->hasHeader('Date'));
            $this->assertEquals((string) strlen($message), $request->getHeaderLine('Content-Length'));
            $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertEquals('close', $request->getHeaderLine('Connection'));
            $this->assertEquals('test.me', $request->getHeaderLine('Host'));
            
            yield $socket->write($context, implode("\r\n", [
                'HTTP/1.1 201 Form posted',
                'Connection: close',
                'Content-Type: application/json',
                'Content-Length: ' . strlen($message)
            ]) . "\r\n\r\n" . $message);
        });
    }

    public function testHttpRequestWithChunkedBody(Context $context)
    {
        $message = str_repeat('A', 8192 * 10);
        
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) use ($message) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            
            $request = new HttpRequest('http://test.me/form', Http::POST, [
                'Content-Type' => 'text/plain'
            ], new StreamBody(new ReadableMemoryStream($message)));
            
            $response = yield $conn->send($context, $request, $socket);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals('text/plain', $response->getHeaderLine('Content-Type'));
            $this->assertEquals($message, yield $response->getBody()->getContents($context));
        }, function (Context $context, Socket $socket) use ($message) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::POST, $request->getMethod());            
            $this->assertEquals('chunked', $request->getHeaderLine('Transfer-Encoding'));
            $this->assertEquals('text/plain', $request->getHeaderLine('Content-Type'));
            $this->assertEquals('keep-alive', $request->getHeaderLine('Connection'));
            $this->assertTrue($request->hasHeader('Keep-Alive'));
            
            // Discard body to free connection.
            $body = $parser->parseBodyStream($request, $socket, false);
            $buffer = '';
            
            while (null !== ($chunk = yield $body->read($context))) {
                $buffer .= $chunk;
            }
            
            $body->close();
            
            $this->assertEquals($message, $buffer);
            
            yield $socket->write($context, implode("\r\n", [
                'HTTP/1.1 200 OK',
                'Connection: close',
                'Content-Type: text/plain',
            ]) . "\r\n\r\n" . $message);
        });
    }
    
    public function testWillBufferHttp10BodyToComputeContentLength(Context $context)
    {
        $message = str_repeat('A', 8192 * 10);
        
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) use ($message) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            
            $request = new HttpRequest('http://test.me/form', Http::POST, [
                'Content-Type' => 'text/plain'
            ], new StreamBody(new ReadableMemoryStream($message)), '1.0');
            
            $response = yield $conn->send($context, $request, $socket);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals('1.0', $response->getProtocolVersion());
            $this->assertEquals('text/plain', $response->getHeaderLine('Content-Type'));
            $this->assertEquals($message, yield $response->getBody()->getContents($context));
        }, function (Context $context, Socket $socket) use ($message) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::POST, $request->getMethod());
            $this->assertEquals((string) strlen($message), $request->getHeaderLine('Content-Length'));
            $this->assertEquals('text/plain', $request->getHeaderLine('Content-Type'));
            $this->assertEquals('keep-alive', $request->getHeaderLine('Connection'));
            $this->assertTrue($request->hasHeader('Keep-Alive'));
            
            // Discard body to free connection.
            $body = $parser->parseBodyStream($request, $socket, false);
            $buffer = '';
            
            while (null !== ($chunk = yield $body->read($context))) {
                $buffer .= $chunk;
            }
            
            $body->close();
            
            $this->assertEquals($message, $buffer);
            
            yield $socket->write($context, implode("\r\n", [
                'HTTP/1.0 200 OK',
                'Connection: close',
                'Content-Type: text/plain',
                'Content-Length: ' . strlen($message)
            ]) . "\r\n\r\n" . $message);
        });
    }
    
    public function testExpectContinue(Context $context)
    {
        $message = str_repeat('A', 8192 * 10);
        
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) use ($message) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            
            $request = new HttpRequest('http://test.me/form', Http::POST, [
                'Content-Type' => 'text/plain'
            ], new StringBody($message));
            
            $request = $request->withAttribute(ClientSettings::class, (new ClientSettings())->withExpectContinue(true));
            
            $response = yield $conn->send($context, $request, $socket);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals(Http::CREATED, $response->getStatusCode());
        }, function (Context $context, Socket $socket) use ($message) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::POST, $request->getMethod());
            $this->assertEquals('100-continue', $request->getHeaderLine('Expect'));
            
            yield $socket->write($context, "HTTP/1.1 100 Continue\r\n");
            
            // Discard body to free connection.
            $body = $parser->parseBodyStream($request, $socket, false);
            $buffer = '';
            
            while (null !== ($chunk = yield $body->read($context))) {
                $buffer .= $chunk;
            }
            
            $body->close();
            
            $this->assertEquals($message, $buffer);
            
            yield $socket->write($context, implode("\r\n", [
                'HTTP/1.1 201 Created',
                'Connection: close',
                'Content-Length: 0'
            ]) . "\r\n\r\n");
        });
    }
    
    public function testRequestWillNotWaitForContinueResponseAferTimeoutExpired(Context $context)
    {
        $message = str_repeat('A', 8192 * 10);
        
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) use ($message) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            
            $request = new HttpRequest('http://test.me/form', Http::POST, [
                'Content-Type' => 'text/plain'
            ], new StringBody($message));
            
            $settings = new ClientSettings();
            $settings = $settings->withExpectContinue(true);
            $settings = $settings->withExpectContinueTimeout(200);
            
            $request = $request->withAttribute(ClientSettings::class, $settings);
            
            $response = yield $conn->send($context, $request, $socket);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals(Http::CREATED, $response->getStatusCode());
        }, function (Context $context, Socket $socket) use ($message) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::POST, $request->getMethod());
            $this->assertEquals('100-continue', $request->getHeaderLine('Expect'));
            
            // Discard body to free connection.
            $body = $parser->parseBodyStream($request, $socket, false);
            $buffer = '';
            
            while (null !== ($chunk = yield $body->read($context))) {
                $buffer .= $chunk;
            }
            
            $body->close();
            
            $this->assertEquals($message, $buffer);
            
            yield $socket->write($context, implode("\r\n", [
                'HTTP/1.1 201 Created',
                'Connection: close',
                'Content-Length: 0'
            ]) . "\r\n\r\n");
        });
    }
    
    public function testParseFailureWillDisposeOfConnection(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            $request = new HttpRequest('http://test.me/');
            
            $this->expectException(\RuntimeException::class);
            
            yield $conn->send($context, $request, $socket);
        }, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::GET, $request->getMethod());
            
            yield $socket->write($context, 'FOO');
        });
    }
    
    public function testExpectContinueWithFinalAnswer(Context $context)
    {
        $message = str_repeat('A', 8192 * 10);
        
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) use ($message) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            
            $request = new HttpRequest('http://test.me/form', Http::POST, [
                'Content-Type' => 'text/plain'
            ], new StringBody($message));
            
            $request = $request->withAttribute(ClientSettings::class, (new ClientSettings())->withExpectContinue(true));
            
            $response = yield $conn->send($context, $request, $socket);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals(Http::FORBIDDEN, $response->getStatusCode());
            $this->assertEquals('', yield $response->getBody()->getContents($context));
        }, function (Context $context, Socket $socket) use ($message) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::POST, $request->getMethod());
            $this->assertEquals('100-continue', $request->getHeaderLine('Expect'));
            
            yield $socket->write($context, implode("\r\n", [
                'HTTP/1.1 403 Forbidden',
                'Connection: close',
                'Content-Length: 0'
            ]) . "\r\n\r\n");
        });
    }
    
    public function testUnrequestedContinueResponseIsIgnored(Context $context)
    {
        $message = str_repeat('A', 8192 * 10);
        
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) use ($message) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            
            $request = new HttpRequest('http://test.me/form', Http::PUT, [
                'Content-Type' => 'text/plain'
            ], new StringBody($message));
            
            $response = yield $conn->send($context, $request, $socket);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals(Http::FORBIDDEN, $response->getStatusCode());
            $this->assertEquals('', yield $response->getBody()->getContents($context));
        }, function (Context $context, Socket $socket) use ($message) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::PUT, $request->getMethod());
            
            yield $socket->write($context, "HTTP/1.1 100 Continue\r\n");
            
            // Discard body to free connection.
            $body = $parser->parseBodyStream($request, $socket, false);
            $buffer = '';
            
            while (null !== ($chunk = yield $body->read($context))) {
                $buffer .= $chunk;
            }
            
            $body->close();
            
            $this->assertEquals($message, $buffer);
            
            yield $socket->write($context, implode("\r\n", [
                'HTTP/1.1 403 Forbidden',
                'Connection: close',
                'Content-Length: 0'
            ]) . "\r\n\r\n");
        });
    }
    
    public function testConnectionUpgrade(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            
            $request = new HttpRequest('http://test.me/upgrade', Http::GET, [
                'Connection' => 'upgrade',
                'Upgrade' => 'Test'
            ]);
            
            $response = yield $conn->send($context, $request, $socket);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals(Http::SWITCHING_PROTOCOLS, $response->getStatusCode());
            $this->assertEquals('', yield $response->getBody()->getContents($context));
            
            $upgrade = $response->getAttribute(Upgrade::class);
            
            $this->assertTrue($upgrade instanceof Upgrade);
            $this->assertSame($socket, $upgrade->stream);
            $this->assertEquals((array) 'test', $upgrade->protocols);
        }, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::GET, $request->getMethod());
            $this->assertEquals('keep-alive, upgrade', $request->getHeaderLine('Connection'));
            
            yield $socket->write($context, implode("\r\n", [
                'HTTP/1.1 101 Switching Protocols',
                'Connection: upgrade',
                'Upgrade: Test',
                'Content-Length: 0'
            ]) . "\r\n\r\n");
        });
    }

    public function testUpgradeFailsWhenConnectionHeaderIsMissingUpgradeToken(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            
            $request = new HttpRequest('http://test.me/upgrade', Http::GET, [
                'Connection' => 'upgrade',
                'Upgrade' => 'Test'
            ]);
            
            $this->expectException(UpgradeFailedException::class);
            
            yield $conn->send($context, $request, $socket);
        }, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::GET, $request->getMethod());
            
            yield $socket->write($context, implode("\r\n", [
                'HTTP/1.1 101 Switching Protocols',
                'Connection: close',
                'Upgrade: Test',
                'Content-Length: 0'
            ]) . "\r\n\r\n");
        });
    }

    public function testUpgradeFailsWhenUpgradeHeaderisMissing(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $conn = new Http1Connector(new ConnectionManager($context->getLoop()));
            
            $request = new HttpRequest('http://test.me/upgrade', Http::GET, [
                'Connection' => 'upgrade',
                'Upgrade' => 'Test'
            ]);
            
            $this->expectException(UpgradeFailedException::class);
            
            yield $conn->send($context, $request, $socket);
        }, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            $request = yield from $parser->parseRequest($context, $socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::GET, $request->getMethod());
            
            yield $socket->write($context, implode("\r\n", [
                'HTTP/1.1 101 Switching Protocols',
                'Connection: upgrade',
                'Content-Length: 0'
            ]) . "\r\n\r\n");
        });
    }
}
