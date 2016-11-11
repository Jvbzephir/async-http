<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Response;

use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpResponse;

/**
 * HTTP response that contains a string of XML-encoded data.
 * 
 * @author Martin Schröder
 */
class XmlResponse extends HttpResponse
{
    /**
     * Create an XML repsonse from the given payload.
     * 
     * Supports XML strings, DOM documents / nodes and SimpleXML elements.
     * 
     * @param mixed $payload
     */
    public function __construct($payload)
    {
        if ($payload instanceof \DOMDocument) {
            $payload = $payload->saveXML();
        } elseif ($payload instanceof \DOMNode) {
            $payload = $payload->ownerDocument->saveXml($payload);
        } elseif ($payload instanceof \SimpleXMLElement) {
            $payload = $payload->asXML();
        } else {
            $payload = (string) $payload;
        }
        
        parent::__construct(Http::OK, [
            'Content-Type' => 'application/xml'
        ]);
        
        $this->body = new StringBody($payload);
    }
}
