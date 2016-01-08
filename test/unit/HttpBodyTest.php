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

use KoolKode\Async\ExecutorFactory;
use KoolKode\Async\ExecutorInterface;
use KoolKode\Async\Http\Http1\Http1Connector;
use KoolKode\Async\Http\Http2\Http2Connector;
use KoolKode\Async\Stream\InputStreamInterface;
use KoolKode\Async\Stream\SocketStream;

use function KoolKode\Async\tempStream;
use function KoolKode\Async\runTask;

class HttpBodyTest extends \PHPUnit_Framework_TestCase
{
    protected function createExecutor(): ExecutorInterface
    {
        $executor = (new ExecutorFactory())->createExecutor();
        
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.local.php';
        
        if (is_file($file)) {
            call_user_func(require $file, $executor);
        }
        
        return $executor;
    }

    protected function readContents(InputStreamInterface $in): \Generator
    {
        try {
            $buffer = '';
            
            while (!$in->eof()) {
                $buffer .= yield from $in->read();
            }
            
            return $buffer;
        } finally {
            $in->close();
        }
    }

    public function testHttp1Client()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $connector = new Http1Connector();
            
            $request = new HttpRequest(Uri::parse('https://github.com/koolkode'), yield tempStream());
            $response = yield from $connector->send($request);
            
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
    
    public function testHttp1Server()
    {
        return $this->markTestSkipped('Server Test skipped due to Travis CI');
        
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $server = new HttpEndpoint(12345);
            
            $worker = yield runTask($server->run(function (HttpRequest $request, HttpResponse $response) {
                return $response->withBody(yield tempStream('RECEIVED: ' . (yield from $this->readContents($request->getBody()))));
            }), 'Test Server', true);
            
            try {
                $connector = new Http1Connector();
                
                $message = 'Hi there!';
                $request = new HttpRequest(Uri::parse('http://localhost:12345/test'), yield tempStream($message), 'POST');
                $response = yield from $connector->send($request);
                
                $this->assertTrue($response instanceof HttpResponse);
                $this->assertEquals(Http::CODE_OK, $response->getStatusCode());
                $this->assertEquals('RECEIVED: ' . $message, yield from $this->readContents($response->getBody()));
            } finally {
                $worker->cancel();
            }
        });
        
        $executor->run();
    }
    
    public function testHttp2Client()
    {
        if (!SocketStream::isAlpnSupported()) {
            return $this->markTestSkipped('Test requires ALPN support');
        }
        
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () use ($executor) {
            $connector = new Http2Connector();
            
            $request = new HttpRequest(Uri::parse('https://http2.golang.org/gophertiles'), yield tempStream());
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
