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

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StreamBody;
use KoolKode\Async\Http\StringBody;
use KoolKode\Async\Http\TestLogger;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Test\SocketStreamTester;

/**
 * @covers \KoolKode\Async\Http\Http1\Connector
 * @covers \KoolKode\Async\Http\Http1\EntityStream
 * @covers \KoolKode\Async\Http\Http1\PersistentStream
 */
class ConnectorTest extends AsyncTestCase
{
    public function testSupportedProtocols()
    {
        $connector = new Connector();
        
        $this->assertEquals([
            'http/1.1'
        ], $connector->getProtocols());
        
        $this->assertTrue($connector->isSupported('http/1.1'));
        $this->assertTrue($connector->isSupported(''));
        $this->assertFalse($connector->isSupported('h2'));
    }
    
    public function testBasicGetRequest()
    {
        yield new SocketStreamTester(function (DuplexStream $socket) {
            $connector = new Connector();
            $connector->setKeepAlive(false);
            
            try {
                $request = new HttpRequest(Uri::parse('http://localhost/api?test=yes'));
                $request = $request->withProtocolVersion('1.0');
                
                $context = $connector->getConnectorContext($request->getUri());
                $context->connected = true;
                $context->stream = $socket;
                
                $response = yield $connector->send($context, $request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.0', $response->getProtocolVersion());
                $this->assertEquals(Http::OK, $response->getStatusCode());
                $this->assertEquals('text/plain', $response->getHeaderLine('Content-Type'));
                
                $this->assertEquals('Hello World :)', yield $response->getBody()->getContents());
            } finally {
                $connector->shutdown();
            }
        }, function (DuplexStream $socket) {
            $socket = new PersistentStream($socket, 2);
            $request = yield from (new RequestParser())->parseRequest($socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::GET, $request->getMethod());
            $this->assertEquals('/api?test=yes', $request->getRequestTarget());
            $this->assertEquals('1.0', $request->getProtocolVersion());
            $this->assertEquals('', yield $request->getBody()->getContents());
            
            yield $socket->write(implode("\r\n", [
                'HTTP/1.0 200 OK',
                'Content-Type: text/plain',
                'Content-Length: 14',
                '',
                'Hello World :) XXX'
            ]));
        });
    }
    
    public function testRequestWithPayload()
    {
        yield new SocketStreamTester(function (DuplexStream $socket) {
            $connector = new Connector();
            
            try {
                $payload = [
                    'message' => 'Hello World :)'
                ];
                
                $request = new HttpRequest(Uri::parse('http://localhost/api'), Http::POST);
                $request = $request->withHeader('Content-Type', 'application/json');
                $request = $request->withBody(new StringBody(json_encode($payload)));
                
                $context = $connector->getConnectorContext($request->getUri());
                $context->connected = true;
                $context->stream = $socket;
                
                $response = yield $connector->send($context, $request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.1', $response->getProtocolVersion());
                $this->assertEquals(Http::OK, $response->getStatusCode());
                $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
                
                $payload['processed'] = true;
                
                $this->assertEquals($payload, json_decode(yield $response->getBody()->getContents(), true));
            } finally {
                $connector->shutdown();
            }
        }, function (DuplexStream $socket) {
            $socket = new PersistentStream($socket, 2);
            $request = yield from (new RequestParser())->parseRequest($socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals(Http::POST, $request->getMethod());
            $this->assertEquals('/api', $request->getRequestTarget());
            $this->assertEquals('1.1', $request->getProtocolVersion());
            $this->assertEquals('100-continue', $request->getHeaderLine('Expect'));
            
            $request->getBody()->setExpectContinue($socket);
            $payload = json_decode(yield $request->getBody()->getContents(), true);
            
            $this->assertEquals([
                'message' => 'Hello World :)'
            ], $payload);
            
            $payload['processed'] = true;
            $payload = json_encode($payload);
            
            yield $socket->write(implode("\r\n", [
                'HTTP/1.1 200 OK',
                'Connection: keep-alive',
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
                '',
                $payload
            ]));
        });
    }
    
    public function testhttp10RequestWithPayloadOfUnknownSize()
    {
        $payload = str_repeat('A', 10000);
        
        yield new SocketStreamTester(function (DuplexStream $socket) use ($payload) {
            $connector = new Connector();
            $connector->setExpectContinue(false);
            
            try {
                $request = new HttpRequest(Uri::parse('http://localhost/api'), Http::POST);
                $request = $request->withProtocolVersion('1.0');
                $request = $request->withBody(new StreamBody(new ReadableMemoryStream($payload)));
                
                $context = $connector->getConnectorContext($request->getUri());
                $context->connected = true;
                $context->stream = $socket;
                
                $response = yield $connector->send($context, $request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals($payload, yield $response->getBody()->getContents());
            } finally {
                $connector->shutdown();
            }
        }, function (DuplexStream $socket) use ($payload) {
            $socket = new PersistentStream($socket, 2);
            $request = yield from (new RequestParser())->parseRequest($socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals($payload, yield $request->getBody()->getContents());
            
            yield $socket->write(implode("\r\n", [
                'HTTP/1.0 200 OK',
                'Connection: close',
                '',
                $payload
            ]));
        });
    }
    
    public function testRequestWithPayloadOfUnknownSize()
    {
        $payload = str_repeat('A', 10000);
        
        yield new SocketStreamTester(function (DuplexStream $socket) use ($payload) {
            $connector = new Connector();
            $connector->setExpectContinue(false);
            
            try {
                $request = new HttpRequest(Uri::parse('http://localhost/api'), Http::POST);
                $request = $request->withBody(new StreamBody(new ReadableMemoryStream($payload)));
                
                $context = $connector->getConnectorContext($request->getUri());
                $context->connected = true;
                $context->stream = $socket;
                
                $response = yield $connector->send($context, $request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals($payload, yield $response->getBody()->getContents());
            } finally {
                $connector->shutdown();
            }
        }, function (DuplexStream $socket) use ($payload) {
            $socket = new PersistentStream($socket, 2);
            $request = yield from (new RequestParser())->parseRequest($socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals($payload, yield $request->getBody()->getContents());
            
            yield $socket->write(implode("\r\n", [
                'HTTP/1.1 200 OK',
                'Connection: close',
                '',
                $payload
            ]));
        });
    }
    
    public function testPersistentConnection()
    {
        $logger = new TestLogger();
        
        yield new SocketStreamTester(function (DuplexStream $socket) use ($logger) {
            $connector = new Connector(null, $logger);
            
            try {
                for ($i = 0; $i < 3; $i++) {
                    $request = new HttpRequest(Uri::parse('http://localhost/api'));
                    $request = $request->withProtocolVersion('2.0');
                    
                    $context = $connector->getConnectorContext($request->getUri());
                    
                    if (!$context->connected) {
                        $context->stream = $socket;
                    }
                    
                    $response = yield $connector->send($context, $request);
                    
                    $this->assertTrue($response instanceof HttpResponse);
                    $this->assertEquals('Hello Client :)', yield $response->getBody()->getContents());
                }
            } finally {
                $connector->shutdown();
            }
        }, function (DuplexStream $socket) {
            $socket = new PersistentStream($socket, 10);
            
            for ($i = 0; $i < 3; $i++) {
                $request = yield from (new RequestParser())->parseRequest($socket);
                
                $this->assertTrue($request instanceof HttpRequest);
                $this->assertEquals('1.1', $request->getProtocolVersion());
                $this->assertEquals([
                    'keep-alive'
                ], $request->getHeaderTokens('Connection'));
                
                yield $socket->write(implode("\r\n", [
                    'HTTP/1.1 200 OK',
                    'Connection: keep-alive',
                    'Content-Length: 15',
                    '',
                    'Hello Client :)'
                ]));
            }
        });
        
        $this->assertCount(11, $logger);
    }
    
    public function testFinalResponseBeforeExpectContinue()
    {
        $payload = str_repeat('A', 10000);
        
        yield new SocketStreamTester(function (DuplexStream $socket) use ($payload) {
            $connector = new Connector();
            
            try {
                $request = new HttpRequest(Uri::parse('http://localhost/api'), Http::POST);
                $request = $request->withBody(new StringBody($payload));
                
                $context = $connector->getConnectorContext($request->getUri());
                $context->connected = true;
                $context->stream = $socket;
                
                $response = yield $connector->send($context, $request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals(Http::SEE_OTHER, $response->getStatusCode());
                $this->assertEquals('http://localhost/other', $response->getHeaderLine('Location'));
                $this->assertEquals('', yield $response->getBody()->getContents());
            } finally {
                $connector->shutdown();
            }
        }, function (DuplexStream $socket) use ($payload) {
            $socket = new PersistentStream($socket, 2);
            $request = yield from (new RequestParser())->parseRequest($socket);
            
            $this->assertTrue($request instanceof HttpRequest);
            $this->assertEquals('100-continue', $request->getHeaderLine('Expect'));
            
            yield $socket->write(implode("\r\n", [
                'HTTP/1.1 303 See Other',
                'Location: http://localhost/other',
                '',
                $payload
            ]));
        });
    }
}