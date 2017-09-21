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
 * @covers \KoolKode\Async\Http\Response\FileResponse
 */
class FileResponseTest extends AsyncTestCase
{
    public function testWillGuessContentType(Context $context)
    {
        $response = new FileResponse(__FILE__);
        
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('application/x-httpd-php', (string) $response->getContentType()->getMediaType());
        $this->assertEquals(file_get_contents(__FILE__), yield $response->getBody()->getContents($context));
    }
    
    public function testTextFileResponseConstruction(Context $context)
    {
        $response = new FileResponse(__FILE__, 'text/plain');
        
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('text/plain', (string) $response->getContentType()->getMediaType());
        $this->assertEquals('utf-8', $response->getContentType()->getParam('charset'));
        $this->assertEquals(file_get_contents(__FILE__), yield $response->getBody()->getContents($context));
    }
    
    public function testCanChangeCharset(Context $context)
    {
        $response = new FileResponse(__FILE__, 'text/css', 'iso-8859-1');
    
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('text/css', (string) $response->getContentType()->getMediaType());
        $this->assertEquals('iso-8859-1', $response->getContentType()->getParam('charset'));
        $this->assertEquals(file_get_contents(__FILE__), yield $response->getBody()->getContents($context));
    }
    
    public function testCanCreateContentDisposition()
    {
        $response = new FileResponse(__FILE__);
        $response = $response->withContentDisposition('test.txt');
        
        $this->assertEquals('attachment;filename="test.txt"', $response->getHeaderLine('Content-Disposition'));
    }
}
