<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Middleware;

use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Middleware\MiddlewareSupported
 * @covers \KoolKode\Async\Http\Middleware\RegisteredMiddleware
 */
class MiddlewareSupportedTest extends AsyncTestCase
{
    public function testWillSortMiddlewareByPriority()
    {
        $a = function () {};
        $b = function () {};
        $c = function () {};
        
        $base = $this->createBase();
        $base = $base->withMiddleware($a);
        $base = $base->withMiddleware($b, 10);
        $base = $base->withMiddleware($c, 10);
        
        $this->assertCount(3, $base->getMiddlewares());
        $this->assertSame($b, $base->getMiddlewares()[0]->callback);
        $this->assertSame($c, $base->getMiddlewares()[1]->callback);
        $this->assertSame($a, $base->getMiddlewares()[2]->callback);
    }

    public function testCanRegisterInterfaceBasedMiddlewareWithDefaultPriority()
    {
        $base = $this->createBase();
        
        $this->assertCount(0, $base->getMiddlewares());
        $this->assertNotSame($base, $base = $base->withMiddleware($middleware = new ResponseContentDecoder()));
        $this->assertCount(1, $base->getMiddlewares());
        
        $registration = $base->getMiddlewares()[0];
        
        $this->assertTrue($registration instanceof RegisteredMiddleware);
        $this->assertSame($middleware, $registration->callback);
        $this->assertEquals($middleware->getDefaultPriority(), $registration->priority);
    }

    protected function createBase()
    {
        return new class() {
            use MiddlewareSupported;

            public function getMiddlewares(): array
            {
                return $this->middlewares;
            }
        };
    }
}
