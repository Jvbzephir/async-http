<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Header;

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpResponse;

/**
 * @covers \KoolKode\Async\Http\Header\Accept
 */
class AcceptTest extends \PHPUnit_Framework_TestCase
{
    public function testAccessorsAndConstructionFromMessage()
    {
        $message = new HttpResponse(Http::OK, [
            'Accept' => 'application/xml;q=0.9,text/html,application/xhtml+xml,text/*;q=0.8'
        ]);
        
        $result = [
            'application/xhtml+xml',
            'text/html',
            'application/xml',
            'text/*'
        ];
        
        $accept = Accept::fromMessage($message);
        
        $this->assertCount(4, $accept);
        $this->assertEquals($result, array_map('trim', $accept->getMediaTypes()));
        
        $this->assertTrue($accept->accepts('text/plain'));
        $this->assertFalse($accept->accepts('image/png'));
        
        foreach ($accept as $i => $type) {
            $this->assertTrue($type instanceof ContentType);
            
            $this->assertEquals($result[$i], $type->getMediaType());
        }
    }

    public function testConvertsTokensToContentTypes()
    {
        $accept = new Accept(new HeaderToken('text/html'), new HeaderToken('application/xhtml+xml'));
        
        $result = [
            'application/xhtml+xml',
            'text/html'
        ];
        
        $this->assertTrue($accept->accepts('application/xml'));
        $this->assertFalse($accept->accepts('text/xml'));
        
        foreach ($accept as $i => $type) {
            $this->assertTrue($type instanceof ContentType);
            
            $this->assertEquals($result[$i], $type->getMediaType());
        }
    }
}
