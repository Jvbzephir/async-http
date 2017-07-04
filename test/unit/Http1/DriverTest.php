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
use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Test\SocketStreamTester;

/**
 * @covers \KoolKode\Async\Http\Http1\Driver
 * @covers \KoolKode\Async\Http\HttpDriverContext
 */
class DriverTest extends AsyncTestCase
{
    public function testSupportedProtocols()
    {
        $driver = new Driver();
        
        $this->assertEquals(11, $driver->getPriority());
        $this->assertEquals([
            'http/1.1'
        ], $driver->getProtocols());
    }
    
    public function testSimpleHttp10Request()
    {
        yield new SocketStreamTester(function (DuplexStream $stream) {
            yield $stream->write(implode("\r\n", [
                'GET / HTTP/1.0',
                'Host: localhost',
                'Content-Length: 0',
                '',
                ''
            ]));
            
            $response = yield from (new ResponseParser())->parseResponse($stream);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.0', $response->getProtocolVersion());
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals('OK', $response->getReasonPhrase());
            $this->assertEquals('close', $response->getHeaderLine('Connection'));
            $this->assertEquals('text/plain', $response->getHeaderLine('Content-Type'));
            $this->assertEquals('Hello Client :)', yield $response->getBody()->getContents());
        }, function (DuplexStream $stream) {
            $driver = new Driver();
            
            yield $driver->handleConnection(new HttpDriverContext(), $stream, function (HttpRequest $request) {
                $this->assertEquals(Http::GET, $request->getMethod());
                $this->assertEquals('/', $request->getRequestTarget());
                $this->assertEquals('1.0', $request->getProtocolVersion());
                $this->assertEquals('localhost', $request->getHeaderLine('Host'));
                $this->assertEquals('', yield $request->getBody()->getContents());
                
                return new HttpResponse(Http::OK, [
                    'Content-Type' => 'text/plain'
                ], new StringBody('Hello Client :)'));
            });
        });
    }
    
    public function testResponseWithoutBody()
    {
        yield new SocketStreamTester(function (DuplexStream $stream) {
            yield $stream->write(implode("\r\n", [
                'OPTIONS * HTTP/1.1',
                'Host: localhost',
                '',
                ''
            ]));
            
            $response = yield from (new ResponseParser())->parseResponse($stream);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
            $this->assertEquals('close', $response->getHeaderLine('Connection'));
            $this->assertEquals('', yield $response->getBody()->getContents());
        }, function (DuplexStream $stream) {
            $driver = new Driver();
            $driver->setKeepAliveSupported(false);
            
            yield $driver->handleConnection(new HttpDriverContext(), $stream, function (HttpRequest $request) {
                $this->assertEquals(Http::OPTIONS, $request->getMethod());
                $this->assertEquals('*', $request->getRequestTarget());
                $this->assertEquals('1.1', $request->getProtocolVersion());
                $this->assertEquals('localhost', $request->getHeaderLine('Host'));
                $this->assertEquals('', yield $request->getBody()->getContents());
                
                return new HttpResponse(Http::NO_CONTENT);
            });
        });
    }
    
    public function testCanCloseHttp1Connection()
    {
        yield new SocketStreamTester(function (DuplexStream $stream) {
            yield $stream->write(implode("\r\n", [
                'GET / HTTP/1.1',
                'Host: localhost',
                'Connection: close',
                '',
                ''
            ]));
            
            $response = yield from (new ResponseParser())->parseResponse($stream);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
            $this->assertEquals('close', $response->getHeaderLine('Connection'));
            $this->assertEquals('', yield $response->getBody()->getContents());
        }, function (DuplexStream $stream) {
            $driver = new Driver();
            
            yield $driver->handleConnection(new HttpDriverContext(), $stream, function (HttpRequest $request) {
                return new HttpResponse(Http::NO_CONTENT);
            });
        });
    }
    
    public function testCanCopyHttp10ContentsOfUnknownSize()
    {
        $payload = random_bytes(20000);
        
        yield new SocketStreamTester(function (DuplexStream $stream) use ($payload) {
            yield $stream->write(implode("\r\n", [
                'POST /api HTTP/1.0',
                'Host: localhost',
                'Content-Type: application/otet-stream',
                'Content-Length: ' . strlen($payload),
                '',
                $payload
            ]));
            
            $response = yield from (new ResponseParser())->parseResponse($stream);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.0', $response->getProtocolVersion());
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals('close', $response->getHeaderLine('Connection'));
            $this->assertEquals($payload, yield $response->getBody()->getContents());
        }, function (DuplexStream $stream) use ($payload) {
            $driver = new Driver();
            
            yield $driver->handleConnection(new HttpDriverContext(), $stream, function (HttpRequest $request) use ($payload) {
                $this->assertEquals(Http::POST, $request->getMethod());
                $this->assertEquals('/api', $request->getRequestTarget());
                $this->assertEquals('1.0', $request->getProtocolVersion());
                $this->assertEquals('localhost', $request->getHeaderLine('Host'));
                $this->assertEquals($payload, yield $request->getBody()->getContents());
                
                return new HttpResponse(Http::OK, [
                    'Content-Type' => 'application/otet-stream'
                ], new StreamBody(new ReadableMemoryStream($payload)));
            });
        });
    }
    
    public function testHeadRequest()
    {
        yield new SocketStreamTester(function (DuplexStream $stream) {
            yield $stream->write(implode("\r\n", [
                'HEAD /api HTTP/1.0',
                'Host: localhost',
                '',
                ''
            ]));
            
            $response = yield from (new ResponseParser())->parseResponse($stream);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.0', $response->getProtocolVersion());
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals('close', $response->getHeaderLine('Connection'));
            $this->assertEquals('', yield $response->getBody()->getContents());
        }, function (DuplexStream $stream) {
            $driver = new Driver();
            
            yield $driver->handleConnection(new HttpDriverContext(), $stream, function (HttpRequest $request) {
                $this->assertEquals(Http::HEAD, $request->getMethod());
                $this->assertEquals('/api', $request->getRequestTarget());
                $this->assertEquals('1.0', $request->getProtocolVersion());
                $this->assertEquals('localhost', $request->getHeaderLine('Host'));
                
                return new HttpResponse(Http::OK, [
                    'Content-Type' => 'application/otet-stream'
                ], new StreamBody(new ReadableMemoryStream(\str_repeat('A', 9876))));
            });
        });
    }
    
    public function testKeepHttp11Alive()
    {
        $payload = random_bytes(20000);
        
        yield new SocketStreamTester(function (DuplexStream $stream) use ($payload) {
            for ($i = 0; $i < 3; $i++) {
                yield $stream->write(implode("\r\n", array_merge([
                    'POST /api HTTP/1.1',
                    'Host: localhost',
                    'Content-Type: application/otet-stream',
                    'Content-Length: ' . strlen($payload),
                    '',
                    $payload
                ])));
                
                $response = yield from (new ResponseParser())->parseResponse($stream);
                $response->getBody()->setCascadeClose(false);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.1', $response->getProtocolVersion());
                $this->assertEquals(Http::OK, $response->getStatusCode());
                $this->assertEquals('keep-alive', $response->getHeaderLine('Connection'));
                $this->assertEquals($payload, yield $response->getBody()->getContents());
            }
        }, function (DuplexStream $stream) use ($payload) {
            $driver = new Driver();
            
            yield $driver->handleConnection(new HttpDriverContext(), $stream, function (HttpRequest $request) use ($payload) {
                $this->assertEquals(Http::POST, $request->getMethod());
                $this->assertEquals('/api', $request->getRequestTarget());
                $this->assertEquals('1.1', $request->getProtocolVersion());
                $this->assertEquals('localhost', $request->getHeaderLine('Host'));
                $this->assertEquals($payload, yield $request->getBody()->getContents());
                
                return new HttpResponse(Http::OK, [
                    'Content-Type' => 'application/otet-stream'
                ], new StreamBody(new ReadableMemoryStream($payload)));
            });
        });
    }
    
    public function testSupportsExpectContinue()
    {
        $payload = str_repeat('A', 746);
        
        yield new SocketStreamTester(function (DuplexStream $stream) use ($payload) {
            yield $stream->write(implode("\r\n", [
                'POST /api HTTP/1.1',
                'Host: localhost',
                'Expect: 100-continue',
                'Content-Type: text/plain',
                'Content-Length: ' . strlen($payload),
                '',
                ''
            ]));
            
            $this->assertEquals('HTTP/1.1 100 Continue', yield $stream->readLine());
            
            yield $stream->write($payload);
            
            $response = yield from (new ResponseParser())->parseResponse($stream);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals($payload, yield $response->getBody()->getContents());
        }, function (DuplexStream $stream) use ($payload) {
            $driver = new Driver();
            
            yield $driver->handleConnection(new HttpDriverContext(), $stream, function (HttpRequest $request) use ($payload) {
                $this->assertEquals(Http::POST, $request->getMethod());
                $this->assertEquals('/api', $request->getRequestTarget());
                $this->assertEquals('1.1', $request->getProtocolVersion());
                $this->assertEquals('localhost', $request->getHeaderLine('Host'));
                
                $this->assertEquals($payload, yield $request->getBody()->getContents());
                
                return new HttpResponse(Http::OK, [
                    'Content-Type' => 'text/plain'
                ], new StringBody($payload));
            });
        });
    }
    
    public function testWillNotRequestBodyIfNotNeeded()
    {
        yield new SocketStreamTester(function (DuplexStream $stream) {
            yield $stream->write(implode("\r\n", [
                'POST /api HTTP/1.1',
                'Host: localhost',
                'Expect: 100-continue',
                'Content-Type: text/plain',
                'Content-Length: 123',
                '',
                ''
            ]));
            
            $response = yield from (new ResponseParser())->parseResponse($stream);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::SEE_OTHER, $response->getStatusCode());
            $this->assertEquals('http://localhost/api/1.0', $response->getHeaderLine('Location'));
        }, function (DuplexStream $stream) {
            $driver = new Driver();
            
            yield $driver->handleConnection(new HttpDriverContext(), $stream, function (HttpRequest $request) {
                $this->assertEquals(Http::POST, $request->getMethod());
                $this->assertEquals('/api', $request->getRequestTarget());
                $this->assertEquals('1.1', $request->getProtocolVersion());
                $this->assertEquals('localhost', $request->getHeaderLine('Host'));
                
                return new HttpResponse(Http::SEE_OTHER, [
                    'Location' => 'http://localhost/api/1.0'
                ]);
            });
        });
    }
    
    public function testErrorResponse()
    {
        yield new SocketStreamTester(function (DuplexStream $stream) {
            yield $stream->write(implode("\r\n", array_merge([
                'GET / HTTP/1.1',
                'Host: localhost',
                '',
                ''
            ])));
            
            $response = yield from (new ResponseParser())->parseResponse($stream);
            $response->getBody()->setCascadeClose(false);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::INTERNAL_SERVER_ERROR, $response->getStatusCode());
        }, function (DuplexStream $stream) {
            $driver = new Driver();
            
            yield $driver->handleConnection(new HttpDriverContext(), $stream, function (HttpRequest $request) {
                return new \stdClass();
            });
        });
    }
    
    public function testErrorStatusResponse()
    {
        yield new SocketStreamTester(function (DuplexStream $stream) {
            yield $stream->write(implode("\r\n", array_merge([
                'GET / HTTP/1.1',
                'Host: localhost',
                '',
                ''
            ])));
            
            $response = yield from (new ResponseParser())->parseResponse($stream);
            $response->getBody()->setCascadeClose(false);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::PRECONDITION_FAILED, $response->getStatusCode());
        }, function (DuplexStream $stream) {
            $driver = new Driver();
            
            yield $driver->handleConnection(new HttpDriverContext(), $stream, function (HttpRequest $request) {
                throw new StatusException(Http::PRECONDITION_FAILED, 'Failed to check condition');
            });
        });
    }
    
    public function testDetectsMissingHostHeaderInHttp11Request()
    {
        yield new SocketStreamTester(function (DuplexStream $stream) {
            yield $stream->write(implode("\r\n", array_merge([
                'GET / HTTP/1.1',
                'Content-Length: 0',
                '',
                ''
            ])));
            
            $response = yield from (new ResponseParser())->parseResponse($stream);
            $response->getBody()->setCascadeClose(false);
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::BAD_REQUEST, $response->getStatusCode());
        }, function (DuplexStream $stream) {
            $driver = new Driver();
            
            yield $driver->handleConnection(new HttpDriverContext(), $stream, function () {});
        });
    }
}
