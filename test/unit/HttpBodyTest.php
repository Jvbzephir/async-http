<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

use KoolKode\Async\Http\Header\AcceptHeader;
use KoolKode\Async\Http\Header\ContentType;
use KoolKode\Async\Http\Header\ContentTypeHeader;
use KoolKode\Async\Http\Http1\Http1Connector;
use KoolKode\Async\Http\Http2\Http2Connector;
use KoolKode\Async\Stream\Stream;
use KoolKode\Async\Stream\StringInputStream;
use KoolKode\Async\Test\AsyncTrait;

use function KoolKode\Async\runTask;

class HttpBodyTest extends \PHPUnit_Framework_TestCase
{
    use AsyncTrait;
    
    public function provideConnectors()
    {
        yield [
            []
        ];
        
        if (Http2Connector::isAvailable()) {
            yield [
                [
                    new Http2Connector()
                ]
            ];
        }
    }

    /**
     * @dataProvider provideConnectors
     */
    public function testClient(array $connectors)
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () use ($connectors) {
            $client = new HttpClient();
            
            foreach ($connectors as $connector) {
                $client->addConnector($connector);
            }
            
            $options = [
                'ssl' => [
                    'ciphers' => 'DEFAULT'
                ]
            ];
            
            $request = new HttpRequest('https://http2.golang.org/reqinfo');
            $request2 = new HttpRequest('https://http2.golang.org/reqinfo');
            
            try {
                $response = yield from $client->send($request, $options);
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals(Http::CODE_OK, $response->getStatusCode());
                
                $body = yield from Stream::readContents($response->getBody());
                $this->assertNotFalse(stripos($body, 'Protocol: HTTP/' . $response->getProtocolVersion()));
                
                $response = yield from $client->send($request2, $options);
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals(Http::CODE_OK, $response->getStatusCode());
                
                $body = yield from Stream::readContents($response->getBody());
                $this->assertNotFalse(stripos($body, 'Protocol: HTTP/' . $response->getProtocolVersion()));
            } finally {
                $client->shutdown();
            }
        });
        
        $executor->run();
    }
    
    public function testHttp1Client()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $connector = new Http1Connector();
            
            $context = new HttpConnectorContext();
            $context->options = [
                'ssl' => [
                    'ciphers' => 'DEFAULT'
                ]
            ];
            
            $request = new HttpRequest('https://github.com/koolkode');
            $response = yield from $connector->send($request, $context);
            
            $body = $response->getBody();
            
            try {
                while (!$body->eof()) {
                    yield from $body->read();
                }
            } finally {
                $body->close();
            }
        });
        
        $executor->run();
    }
    
    public function testCTH()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $message = new HttpResponse();
            $message = $message->withHeader('Content-Type', 'text/plain;charset="utf-8"; wrap; max-age="20"');
            
            $type = ContentTypeHeader::fromMessage($message);
            $this->assertEquals('text/plain', (string) $type->getMediaType());
            $this->assertEquals([
                'charset' => 'utf-8',
                'wrap' => true,
                'max-age' => 20
            ], $type->getAttributes());
            
            $this->assertTrue($type->getAttribute('wrap'));
            
            $this->assertFalse($type->hasAttribute('foo'));
            $this->assertEquals('FOO', $type->getAttribute('foo', 'FOO'));
        });
        
        $executor->run();
    }
    
    public function testHeaderParsing()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $message = new HttpResponse();
            $message = $message->withHeader('Accept', 'text/*;q=0.3, text/html;q=0.4, application/xml, text/html;level=1, text/html;level=2;q=0.4, */*;q=0.3');
            
            $accept = AcceptHeader::fromMessage($message);
            $this->assertCount(6, $accept);
            
            $it = $accept->getIterator();
            
            $type = $it->current();
            $this->assertTrue($type instanceof ContentType);
            $this->assertEquals('text/html', (string) $type->getMediaType());
            $this->assertEquals(1, $type->getAttribute('level'));
            $it->next();
            
            $type = $it->current();
            $this->assertTrue($type instanceof ContentType);
            $this->assertEquals('application/xml', (string) $type->getMediaType());
            $it->next();
            
            $type = $it->current();
            $this->assertTrue($type instanceof ContentType);
            $this->assertEquals('text/html', (string) $type->getMediaType());
            $this->assertEquals(2, $type->getAttribute('level'));
            $it->next();
            
            $type = $it->current();
            $this->assertTrue($type instanceof ContentType);
            $this->assertEquals('text/html', (string) $type->getMediaType());
            $it->next();
            
            $type = $it->current();
            $this->assertTrue($type instanceof ContentType);
            $this->assertEquals('text/*', (string) $type->getMediaType());
            $it->next();
            
            $type = $it->current();
            $this->assertTrue($type instanceof ContentType);
            $this->assertEquals('*/*', (string) $type->getMediaType());
            $it->next();
            
            $this->assertFalse($it->valid());
        });
        
        $executor->run();
    }
    
    public function provideChunkedSetting()
    {
        yield [false];
        yield [true];
    }
    
    /**
     * @dataProvider provideChunkedSetting
     */
    public function testHttp1Server(bool $chunked)
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () use ($chunked, $executor) {
            $port = HttpEndpoint::findUnusedPort();
            $server = new HttpEndpoint($port);
            $server->setCiphers('ALL');
            
            $worker = yield runTask($server->run(function (HttpRequest $request, HttpResponse $response) {
                return $response->withBody(new StringInputStream('RECEIVED: ' . (yield from Stream::readContents($request->getBody()))));
            }), 'Test Server', true);
            
            try {
                $connector = new Http1Connector();
                $connector->setChunkedRequests($chunked);
                
                $message = 'Hi there!';
                $request = new HttpRequest(sprintf('http://localhost:%u/test', $port), new StringInputStream($message), 'POST');
                
                if (!$chunked) {
                    $request = $request->withProtocolVersion('1.0');
                }
                
                $response = yield from $connector->send($request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals(Http::CODE_OK, $response->getStatusCode());
                $this->assertEquals('RECEIVED: ' . $message, yield from Stream::readContents($response->getBody()));
            } finally {
                $worker->cancel();
            }
        });
        
        $executor->run();
    }
    
    public function testHttp2Client()
    {
        if (!Http2Connector::isAvailable()) {
            return $this->markTestSkipped('Test requires ALPN support');
        }
        
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () use ($executor) {
            $connector = new Http2Connector();
            
            $request = new HttpRequest('https://http2.golang.org/gophertiles');
            $response = yield from $connector->send($request);
            
            try {
                $body = $response->getBody();
                
                while (!$body->eof()) {
                    yield from $body->read();
                }
            } finally {
                $body->close();
                $connector->shutdown();
            }
        });
        
        $executor->run();
    }
}
