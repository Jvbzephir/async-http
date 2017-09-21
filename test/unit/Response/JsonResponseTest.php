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
 * @covers \KoolKode\Async\Http\Response\JsonResponse
 */
class JsonResponseTest extends AsyncTestCase
{
    public function testResponseConstruction(Context $context)
    {
        $payload = [
            'foo' => 'bar'
        ];
        
        $response = new JsonResponse($payload);
        
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('application/json', (string) $response->getContentType()->getMediaType());
        $this->assertEquals('utf-8', $response->getContentType()->getParam('charset'));
        $this->assertEquals($payload, json_decode(yield $response->getBody()->getContents($context), true));
    }

    public function testCanTransferPreEncodedJson(Context $context)
    {
        $response = new JsonResponse('["foo", "bar"]', false);
        
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('application/json', (string) $response->getContentType()->getMediaType());
        $this->assertEquals('utf-8', $response->getContentType()->getParam('charset'));
        $this->assertEquals([
            'foo',
            'bar'
        ], json_decode(yield $response->getBody()->getContents($context), true));
    }
    
    public function testCanChangeEncoderOptions(Context $context)
    {
        $response = new JsonResponse([], true, JSON_FORCE_OBJECT);
        
        $this->assertEquals(Http::OK, $response->getStatusCode());
        $this->assertEquals('application/json', (string) $response->getContentType()->getMediaType());
        $this->assertEquals('utf-8', $response->getContentType()->getParam('charset'));
        $this->assertEquals('{}', yield $response->getBody()->getContents($context));
    }
}
