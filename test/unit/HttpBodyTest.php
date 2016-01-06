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
use KoolKode\Async\Stream\SocketStream;

use function KoolKode\Async\tempStream;

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

    public function testHttp1Client()
    {
        $executor = $this->createExecutor();
        
        $executor->runNewTask(call_user_func(function () {
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
        }));
        
        $executor->run();
    }
    
    public function testHttp2Client()
    {
        if (!SocketStream::isAlpnSupported()) {
            return $this->markTestSkipped('Test requires ALPN support');
        }
        
        $executor = $this->createExecutor();
        
        $executor->runNewTask(call_user_func(function () use ($executor) {
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
        }));
        
        $executor->run();
    }
}
