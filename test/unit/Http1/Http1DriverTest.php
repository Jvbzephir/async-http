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
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableMemoryStream;
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

    public function testDetectsInvalidKeepAlive()
    {
        $driver = new Http1Driver();
        
        $this->expectException(\InvalidArgumentException::class);
        
        $driver->withKeepAlive(-1);
    }

    public function testDetectsInvalidMaxIdleTimeout()
    {
        $driver = new Http1Driver();
        
        $this->expectException(\InvalidArgumentException::class);
        
        $driver->withMaxIdleTime(0);
    }

    public function testWillSilentlyDropConnectionOnEofBeforeHeaders(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            yield $socket->write($context, "HEAD /foo HTTP/1.0\r\n");
            $socket->closeWrite();
            
            $this->assertNull(yield $socket->read($context));
        }, function (Context $context, Socket $socket) {
            yield (new Http1Driver())->listen($context, $socket, function () {});
        });
    }

    public function testHttp10HeadRequest(Context $context)
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
            $this->assertEquals(Http::NOT_FOUND, $response->getStatusCode());
            $this->assertEquals('', yield $response->getBody()->getContents($context));
        }, function (Context $context, Socket $socket) {
            $driver = new Http1Driver();
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) {
                $this->assertEquals(Http::HEAD, $request->getMethod());
                $this->assertEquals('1.0', $request->getProtocolVersion());
                $this->assertEquals('/foo', $request->getRequestTarget());
                $this->assertEquals('', yield $request->getBody()->getContents($context));
                
                return yield from $responder($context, \call_user_func(function () {
                    yield null;
                    
                    return new HttpResponse(Http::NOT_FOUND, [], new StringBody('FOO'));
                }));
            });
        });
    }

    public function testDetectsResultThatCannotBeConvertedIntoResponse(Context $context)
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
            $this->assertEquals(Http::INTERNAL_SERVER_ERROR, $response->getStatusCode());
            $this->assertEquals('', yield $response->getBody()->getContents($context));
        }, function (Context $context, Socket $socket) {
            $driver = new Http1Driver();
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) {
                return yield from $responder($context, 123);
            });
        });
    }

    public function testCanUpgradeConnection(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            
            yield $socket->write($context, implode("\r\n", [
                'GET / HTTP/1.1',
                'Content-Length: 0',
                'Connection: upgrade',
                'Upgrade: foo',
                'Foo: bar',
                "\r\n"
            ]));
            
            $response = yield from $parser->parseResponse($context, $socket);
            $response = $response->withBody(new StreamBody($parser->parseBodyStream($response, $socket, false)));
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::SWITCHING_PROTOCOLS, $response->getStatusCode());
            $this->assertEquals('', yield $response->getBody()->getContents($context));
            $this->assertEquals('bar', yield $socket->readBuffer($context, 1000, false));
        }, function (Context $context, Socket $socket) {
            $driver = new Http1Driver();
            $driver = $driver->withUpgradeResultHandler(new class() implements UpgradeResultHandler {

                public function isUpgradeSupported(string $protocol, HttpRequest $request, $result): bool
                {
                    return $protocol == 'foo' && \is_array($result);
                }

                public function createUpgradeResponse(HttpRequest $request, $result): HttpResponse
                {
                    return new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                        'Upgrade' => 'foo'
                    ]);
                }

                public function upgradeConnection(Context $context, DuplexStream $stream, HttpRequest $request, HttpResponse $response): \Generator
                {
                    yield $stream->write($context, $request->getHeaderLine('Foo'));
                }
            });
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) {
                return yield from $responder($context, []);
            });
        });
    }

    public function testCanFailUpgrade(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            
            yield $socket->write($context, implode("\r\n", [
                'GET / HTTP/1.1',
                'Content-Length: 0',
                'Connection: upgrade',
                'Upgrade: foo',
                'Foo: bar',
                "\r\n"
            ]));
            
            $response = yield from $parser->parseResponse($context, $socket);
            $response = $response->withBody(new StreamBody($parser->parseBodyStream($response, $socket, false)));
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::CONFLICT, $response->getStatusCode());
            $this->assertEquals('', yield $response->getBody()->getContents($context));
            
            $this->assertNull(yield $socket->read($context));
        }, function (Context $context, Socket $socket) {
            $driver = new Http1Driver();
            $driver = $driver->withUpgradeResultHandler(new class() implements UpgradeResultHandler {

                public function isUpgradeSupported(string $protocol, HttpRequest $request, $result): bool
                {
                    return $protocol == 'foo' && \is_array($result);
                }

                public function createUpgradeResponse(HttpRequest $request, $result): HttpResponse
                {
                    return new HttpResponse(Http::CONFLICT);
                }

                public function upgradeConnection(Context $context, DuplexStream $stream, HttpRequest $request, HttpResponse $response): \Generator
                {}
            });
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) {
                return yield from $responder($context, []);
            });
        });
    }

    public function testCanFailAfterUpgrade(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            
            yield $socket->write($context, implode("\r\n", [
                'GET / HTTP/1.1',
                'Content-Length: 0',
                'Connection: upgrade',
                'Upgrade: foo',
                'Foo: bar',
                "\r\n"
            ]));
            
            $response = yield from $parser->parseResponse($context, $socket);
            $response = $response->withBody(new StreamBody($parser->parseBodyStream($response, $socket, false)));
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::SWITCHING_PROTOCOLS, $response->getStatusCode());
            $this->assertEquals('', yield $response->getBody()->getContents($context));
            
            $this->assertNull(yield $socket->read($context));
        }, function (Context $context, Socket $socket) {
            $driver = new Http1Driver();
            $driver = $driver->withUpgradeResultHandler(new class() implements UpgradeResultHandler {

                public function isUpgradeSupported(string $protocol, HttpRequest $request, $result): bool
                {
                    return $protocol == 'foo' && \is_array($result);
                }

                public function createUpgradeResponse(HttpRequest $request, $result): HttpResponse
                {
                    return new HttpResponse(Http::SWITCHING_PROTOCOLS, [
                        'Upgrad' => 'foo'
                    ]);
                }

                public function upgradeConnection(Context $context, DuplexStream $stream, HttpRequest $request, HttpResponse $response): \Generator
                {
                    yield null;
                    
                    throw new \LogicException('Fail');
                }
            });
            
            $this->expectException(\LogicException::class);
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) {
                return yield from $responder($context, []);
            });
        });
    }

    public function testCanSendBodyWithContentLength(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            
            yield $socket->write($context, implode("\r\n", [
                'GET / HTTP/1.1',
                'Content-Length: 0',
                "\r\n"
            ]));
            
            $response = yield from $parser->parseResponse($context, $socket);
            $response = $response->withBody(new StreamBody($parser->parseBodyStream($response, $socket, false)));
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals(11, (int) $response->getHeaderLine('Content-Length'));
            $this->assertEquals('Hello World', yield $response->getBody()->getContents($context));
            
            $this->assertNull(yield $socket->read($context));
        }, function (Context $context, Socket $socket) {
            $driver = new Http1Driver();
            $driver = $driver->withKeepAlive(0);
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) {
                $this->assertEquals(Http::GET, $request->getMethod());
                $this->assertEquals('1.1', $request->getProtocolVersion());
                $this->assertEquals('/', $request->getRequestTarget());
                $this->assertEquals('', yield $request->getBody()->getContents($context));
                
                return yield from $responder($context, new HttpResponse(Http::OK, [
                    'Conent-Type' => 'text/plain'
                ], new StringBody('Hello World')));
            });
        });
    }

    public function testCanSendChunkEncodedBody(Context $context)
    {
        $message = str_repeat('A', 0xFFFF);
        
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) use ($message) {
            $parser = new MessageParser();
            
            yield $socket->write($context, implode("\r\n", [
                'GET / HTTP/1.1',
                'Content-Length: 0',
                'Connection: close',
                "\r\n"
            ]));
            
            $response = yield from $parser->parseResponse($context, $socket);
            $response = $response->withBody(new StreamBody($parser->parseBodyStream($response, $socket)));
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals('chunked', $response->getHeaderLine('Transfer-Encoding'));
            $this->assertEquals($message, yield $response->getBody()->getContents($context));
        }, function (Context $context, Socket $socket) use ($message) {
            $driver = new Http1Driver();
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) use ($message) {
                $this->assertEquals(Http::GET, $request->getMethod());
                $this->assertEquals('1.1', $request->getProtocolVersion());
                $this->assertEquals('/', $request->getRequestTarget());
                $this->assertEquals('', yield $request->getBody()->getContents($context));
                
                return yield from $responder($context, new HttpResponse(Http::OK, [
                    'Conent-Type' => 'text/plain'
                ], new StreamBody(new ReadableMemoryStream($message))));
            });
        });
    }

    public function testBodyWithUnknownLengthIsBufferedForHttp10(Context $context)
    {
        $payload = str_repeat('A', 8192 * 10);
        
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) use ($payload) {
            $parser = new MessageParser();
            
            yield $socket->write($context, implode("\r\n", [
                'GET / HTTP/1.0',
                'Content-Length: 0',
                "\r\n"
            ]));
            
            $response = yield from $parser->parseResponse($context, $socket);
            $response = $response->withBody(new StreamBody($parser->parseBodyStream($response, $socket, false)));
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.0', $response->getProtocolVersion());
            $this->assertEquals(Http::OK, $response->getStatusCode());
            $this->assertEquals(strlen($payload), (int) $response->getHeaderLine('Content-Length'));
            $this->assertEquals($payload, yield $response->getBody()->getContents($context));
            
            $this->assertNull(yield $socket->read($context));
        }, function (Context $context, Socket $socket) use ($payload) {
            $driver = new Http1Driver();
            $driver = $driver->withKeepAlive(0);
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) use ($payload) {
                $this->assertEquals(Http::GET, $request->getMethod());
                $this->assertEquals('1.0', $request->getProtocolVersion());
                $this->assertEquals('/', $request->getRequestTarget());
                $this->assertEquals('', yield $request->getBody()->getContents($context));
                
                return yield from $responder($context, new HttpResponse(Http::OK, [
                    'Conent-Type' => 'text/plain'
                ], new StreamBody(new ReadableMemoryStream($payload))));
            });
        });
    }

    public function testServerEnforcesMaxNumberOfRequests(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            
            for ($i = 0; $i < 2; $i++) {
                yield $socket->write($context, implode("\r\n", [
                    'GET / HTTP/1.1',
                    'Content-Length: 0',
                    "\r\n"
                ]));
                
                $response = yield from $parser->parseResponse($context, $socket);
                $response = $response->withBody(new StreamBody($parser->parseBodyStream($response, $socket, false)));
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.1', $response->getProtocolVersion());
                $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
                $this->assertTrue($response->hasHeader('keep-Alive'));
                $this->assertEquals('', yield $response->getBody()->getContents($context));
            }
            
            $this->assertNull(yield $socket->read($context));
        }, function (Context $context, Socket $socket) {
            $driver = new Http1Driver();
            $driver = $driver->withKeepAlive(2);
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) {
                return yield from $responder($context, new HttpResponse(Http::NO_CONTENT));
            });
        });
    }

    public function testServerEnforcesIdleTimeoutInRequests(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            
            for ($i = 0; $i < 2; $i++) {
                yield $socket->write($context, implode("\r\n", [
                    'GET / HTTP/1.1',
                    'Content-Length: 0',
                    "\r\n"
                ]));
                
                $response = yield from $parser->parseResponse($context, $socket);
                $response = $response->withBody(new StreamBody($parser->parseBodyStream($response, $socket, false)));
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.1', $response->getProtocolVersion());
                $this->assertEquals(Http::NO_CONTENT, $response->getStatusCode());
                $this->assertEquals('', yield $response->getBody()->getContents($context));
            }
            
            $this->assertNull(yield $socket->read($context));
        }, function (Context $context, Socket $socket) {
            $driver = new Http1Driver();
            $driver = $driver->withMaxIdleTime(1);
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) {
                return yield from $responder($context, new HttpResponse(Http::NO_CONTENT));
            });
        });
    }

    public function testExpectContinueWillSendContinue(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            
            yield $socket->write($context, implode("\r\n", [
                'PUT /test HTTP/1.1',
                'Content-Type: text/plain',
                'Content-Length: 14',
                'Expect: 100-continue',
                "\r\n"
            ]) . 'Hello World :)');
            
            $response = yield from $parser->parseResponse($context, $socket);
            $this->assertEquals(Http::CONTINUE, $response->getStatusCode());
            
            $response = yield from $parser->parseResponse($context, $socket);
            $response = $response->withBody(new StreamBody($parser->parseBodyStream($response, $socket, false)));
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::CREATED, $response->getStatusCode());
            $this->assertEquals(4, (int) $response->getHeaderLine('Content-Length'));
            $this->assertEquals('DONE', yield $response->getBody()->getContents($context));
            
            $this->assertNull(yield $socket->read($context));
        }, function (Context $context, Socket $socket) {
            $driver = new Http1Driver();
            $driver = $driver->withKeepAlive(0);
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) {
                $this->assertEquals(Http::PUT, $request->getMethod());
                $this->assertEquals('1.1', $request->getProtocolVersion());
                $this->assertEquals('/test', $request->getRequestTarget());
                $this->assertEquals('Hello World :)', yield $request->getBody()->getContents($context));
                
                return yield from $responder($context, new HttpResponse(Http::CREATED, [
                    'Conent-Type' => 'text/plain'
                ], new StringBody('DONE')));
            });
        });
    }

    public function testExpectContinueCanSendFinalResponse(Context $context)
    {
        yield from $this->runSocketTest($context, function (Context $context, Socket $socket) {
            $parser = new MessageParser();
            
            yield $socket->write($context, implode("\r\n", [
                'PUT /test HTTP/1.1',
                'Content-Type: text/plain',
                'Content-Length: 14',
                'Expect: 100-continue',
                "\r\n"
            ]) . 'Hello World :)');
            
            $response = yield from $parser->parseResponse($context, $socket);
            $response = $response->withBody(new StreamBody($parser->parseBodyStream($response, $socket, false)));
            
            $this->assertTrue($response instanceof HttpResponse);
            $this->assertEquals('1.1', $response->getProtocolVersion());
            $this->assertEquals(Http::CREATED, $response->getStatusCode());
            $this->assertEquals(4, (int) $response->getHeaderLine('Content-Length'));
            $this->assertEquals('DONE', yield $response->getBody()->getContents($context));
            
            $this->assertNull(yield $socket->read($context));
        }, function (Context $context, Socket $socket) {
            $driver = new Http1Driver();
            $driver = $driver->withKeepAlive(0);
            
            yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) {
                $this->assertEquals(Http::PUT, $request->getMethod());
                $this->assertEquals('1.1', $request->getProtocolVersion());
                $this->assertEquals('/test', $request->getRequestTarget());
                
                return yield from $responder($context, new HttpResponse(Http::CREATED, [
                    'Conent-Type' => 'text/plain'
                ], new StringBody('DONE')));
            });
        });
    }
}
