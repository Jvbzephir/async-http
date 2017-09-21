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
 * @covers \KoolKode\Async\Http\Response\XmlResponse
 */
class XmlResponseTest extends AsyncTestCase
{
    public function testResponseFromString(Context $context)
    {
        $response = new XmlResponse($payload = 'Hello World!');
        
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('application/xml', $response->getHeaderLine('Content-Type'));
        $this->assertEquals($payload, yield $response->getBody()->getContents($context));
    }
    
    public function testResponseFromSimpleXml(Context $context)
    {
        $xml = simplexml_load_string('<?xml version="1.0"?><foo><bar /></foo>');
        
        $response = new XmlResponse($xml);
    
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('application/xml', $response->getHeaderLine('Content-Type'));
        $this->assertEquals($xml->asXML(), yield $response->getBody()->getContents($context));
    }
    
    public function testResponseFromDomDocument(Context $context)
    {
        $xml = new \DOMDocument();
        $xml->loadXML('<?xml version="1.0"?><foo><bar /></foo>');
        
        $response = new XmlResponse($xml);
        
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('application/xml', $response->getHeaderLine('Content-Type'));
        $this->assertEquals($xml->saveXML(), yield $response->getBody()->getContents($context));
    }
    
    public function testResponseFromDomNode(Context $context)
    {
        $xml = new \DOMDocument();
        $xml->loadXML('<?xml version="1.0"?><foo><bar /></foo>');
        
        $node = $xml->getElementsByTagName('bar')[0];
        
        $response = new XmlResponse($node);
    
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('application/xml', $response->getHeaderLine('Content-Type'));
        $this->assertEquals($xml->saveXML($node), yield $response->getBody()->getContents($context));
    }
}
