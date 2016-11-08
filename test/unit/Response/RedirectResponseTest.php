<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Response;

use KoolKode\Async\Http\Http;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Response\RedirectResponse
 */
class RedirectResponseTest extends AsyncTestCase
{
    public function testResponseConstruction()
    {
        $response = new RedirectResponse($uri = 'http://see.other/target.html');
        
        $this->assertEquals(Http::SEE_OTHER, $response->getStatusCode());
        $this->assertEquals($uri, $response->getHeaderLine('Location'));
    }
    
    public function provideStatusCodes()
    {
        yield [Http::MOVED_PERMANENTLY];
        yield [Http::FOUND];
        yield [Http::SEE_OTHER];
        yield [Http::TEMPORARY_REDIRECT];
        yield [Http::PERMANENT_REDIRECT];
    }
    
    /**
     * @dataProvider provideStatusCodes
     */
    public function testResponseWithDifferentStatus(int $status)
    {
        $response = new RedirectResponse($uri = 'http://see.other/target.html', $status);
    
        $this->assertEquals($status, $response->getStatusCode());
        $this->assertEquals($uri, $response->getHeaderLine('Location'));
    }
    
    public function testDetectsInvalidRedirectStatusCode()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        new RedirectResponse('/', Http::MULTIPLE_CHOICES);
    }
}
