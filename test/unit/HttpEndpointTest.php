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

use KoolKode\Async\Http\Http1\ResponseParser;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\HttpEndpoint
 * @covers \KoolKode\Async\Http\HttpServer
 */
class HttpEndpointTest extends AsyncTestCase
{
    public function testHttp1Roundtrip()
    {
        $endpoint = new HttpEndpoint();
        $server = yield $endpoint->listen();
        
        $this->assertTrue($server instanceof HttpServer);
        
        try {
            $factory = $server->createSocketFactory();
            
            if (Socket::isAlpnSupported()) {
                $factory->setOption('ssl', 'alpn_protocols', 'http/1.1,h2');
            }
            
            $socket = yield $factory->createSocketStream();
            
            try {
                $expect = true;
                
                $request = "POST / HTTP/1.1\r\n";
                $request .= "Host: {$factory->getPeer()}\r\n";
                $request .= "Conection: close\r\n";
                $request .= "Content-Length: 8\r\n";
                
                if (\function_exists('inflate_init')) {
                    $request .= "Accept-Encoding: gzip, deflate\r\n";
                }
                
                if ($expect) {
                    $request .= "Expect: 100-continue\r\n";
                }
                
                $request .= "\r\n";
                
                yield $socket->write($request);
                
                if ($expect) {
                    $this->assertEquals('HTTP/1.1 100 Continue', yield $socket->readLine());
                }
                
                yield $socket->write('Hello :)');
                
                $parser = new ResponseParser();
                $response = yield from $parser->parseResponse($socket);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals('1.1', $response->getProtocolVersion());
                $this->assertEquals(Http::OK, $response->getStatusCode());
                $this->assertEquals('OK', $response->getReasonPhrase());
                $this->assertEquals('KoolKode HTTP', $response->getHeaderLine('Served-By'));
                
                $this->assertEquals('Hello Test Client :)', yield $response->getBody()->getContents());
            } finally {
                $socket->close();
            }
        } finally {
            $server->stop();
        }
    }
}
