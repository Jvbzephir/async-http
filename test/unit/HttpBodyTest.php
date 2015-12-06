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

use function KoolKode\Async\await;
use KoolKode\Async\Http\Http1\Http1Connector;

class HttpBodyTest extends \PHPUnit_Framework_TestCase
{
    public function provideIteratorData()
    {
        yield [
            new \ArrayIterator([
                'Hello',
                ' ',
                'world',
                ' ',
                ':)'
            ])
        ];
        
        yield [
            function () {
                yield 'Hello ';
                yield 'world ';
                yield ':)';
            }
        ];
        
        yield [
            function () {
                yield 'Hello';
                
                $chunk = yield await(call_user_func(function () {
                    yield;
                    
                    return ' world';
                }));
                yield $chunk;
                
                yield ' :)';
            }
        ];
        
        yield [
            function () {
                yield 'Hello';
        
                $chunk = yield from call_user_func(function () {
                    yield;
        
                    return ' world';
                });
                yield $chunk;
        
                yield ' :)';
            }
        ];
    }
    
    /**
     * @dataProvider provideIteratorData
     */
    public function testDataSources($it)
    {
        $executor = (new ExecutorFactory())->createExecutor();
        
        $executor->runNewTask(call_user_func(function () use ($it) {
            $body = new HttpBody($it);
            $contents = '';
            
            while (!$body->eof()) {
                $contents .= yield from $body->read(2, true);
            }
            
            $this->assertEquals('Hello world :)', $contents);
        }));
        
        $executor->run();
    }
    
    public function testClient()
    {
        $executor = (new ExecutorFactory())->createExecutor();
        $executor->setErrorHanndler(function(\Throwable $e) {
            fwrite(STDERR, $e . "\n\n");
        });
        
        $executor->runNewTask(call_user_func(function () {
            $connector = new Http1Connector();
            
            $request = new HttpRequest(Uri::parse('http://phpdeveloper.org/feed'));
            $response = yield from $connector->send($request);
            
            $body = $response->getBody();
            
            while (!$body->eof()) {
                yield from $body->read();
            }
        }));
        
        $executor->run();
    }
}
