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

class HttpBodyTest extends \PHPUnit_Framework_TestCase
{
    protected function createExecutor(): ExecutorInterface
    {
        $executor = (new ExecutorFactory())->createExecutor();
        
        $executor->setErrorHanndler(function (\Throwable $e) {
            fwrite(STDERR, $e . "\n\n");
        });
        
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.local.php';
        
        if (is_file($file)) {
            call_user_func(require $file, $executor);
        }
        
        return $executor;
    }

    public function testClient()
    {
        $executor = $this->createExecutor();
        
        $executor->runNewTask(call_user_func(function () {
            $connector = new Http1Connector();
            
            $request = new HttpRequest(Uri::parse('https://httpbin.org/gzip'));
            $response = yield from $connector->send($request);
            
            $body = $response->getBody();
            
            while (!yield from $body->eof()) {
                fwrite(STDERR, yield from $body->read());
            }
        }));
        
        $executor->run();
    }
}
