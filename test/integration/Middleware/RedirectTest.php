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

use KoolKode\Async\Http\Test\EndToEndTest;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\StringBody;

/**
 * @coversNothing
 */
class RedirectTest extends EndToEndTest
{
    public function testFollowsRedirect()
    {
        $this->clientMiddleware->insert(new FollowRedirects(), 0);
        
        $request = new HttpRequest('http://localhost/test', Http::POST);
        $request = $request->withHeader('Content-Type', 'text/plain');
        $request = $request->withBody(new StringBody('Test Payload :)'));
        
        $response = yield from $this->send($request, function (HttpRequest $request) {
            if ($request->getRequestTarget() === '/test') {
                $response = new HttpResponse(Http::REDIRECT_IDENTICAL);
                $response = $response->withHeader('Location', 'http://localhost/redirected');
                
                return $response;
            }
            
            $response = new HttpResponse();
            $response = $response->withBody(new StringBody('Redirected to <' . $request->getUri() . '>'));
            
            return $response;
        });
        
        $this->assertTrue($response instanceof HttpResponse);
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('Redirected to <http://localhost/redirected>', yield $response->getBody()->getContents());
    }
}
