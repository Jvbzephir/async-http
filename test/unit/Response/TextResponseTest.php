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

use KoolKode\Async\Context;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Response\TextResponse
 */
class TextResponseTest extends AsyncTestCase
{
    public function testCanCreateDefaultTextResponse(Context $context)
    {
        $response = new TextResponse($text = 'Hello World :)');
        
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('text/plain', (string) $response->getContentType()->getMediaType());
        $this->assertEquals('utf-8', $response->getContentType()->getParam('charset'));
        $this->assertEquals($text, yield $response->getBody()->getContents($context));
    }

    public function testCanCreateResponseWithCustomMediaType(Context $context)
    {
        $response = new TextResponse($text = 'h1 { color: red; }', 'text/css');
        
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('text/css', (string) $response->getContentType()->getMediaType());
        $this->assertEquals('utf-8', $response->getContentType()->getParam('charset'));
        $this->assertEquals($text, yield $response->getBody()->getContents($context));
    }
    
    public function testCanCreateResponseWithDifferentCharset(Context $context)
    {
        $response = new TextResponse($text = 'Hello ISO :P', 'text/plain', 'iso-8859-1');
    
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('text/plain', (string) $response->getContentType()->getMediaType());
        $this->assertEquals('iso-8859-1', $response->getContentType()->getParam('charset'));
        $this->assertEquals($text, yield $response->getBody()->getContents($context));
    }
    
    public function testCharsetIsOnlyAppliedToTextTypes(Context $context)
    {
        $response = new TextResponse($text = '*DATA*', 'image/png');
        
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('image/png', (string) $response->getContentType()->getMediaType());
        $this->assertFalse($response->getContentType()->hasParam('charset'));
        $this->assertEquals($text, yield $response->getBody()->getContents($context));
    }
}
