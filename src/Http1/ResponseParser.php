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

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StringBody;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\StreamClosedException;

class ResponseParser extends MessageParser
{
    /**
     * Parse the next HTTP response from the given stream.
     * 
     * @param ReadableStream $stream
     * @param bool $dropBody Drop response body (needed when the response is caused by a HEAD request).
     * @return HttpResponse
     * 
     * @throws StreamClosedException When no HTTP response line could be parsed.
     */
    public function parseResponse(ReadableStream $stream, bool $dropBody = false): \Generator
    {
        $i = 0;
        
        do {
            if ($i++ > 3 || null === ($line = yield $stream->readLine())) {
                throw new StreamClosedException('Stream closed before HTTP response line was read');
            }
        } while ($line === '');
        
        $m = null;
        
        if (!\preg_match("'^HTTP/(1\\.[01])\s+([1-5][0-9]{2})(.*)$'i", \trim($line), $m)) {
            throw new StreamClosedException('Invalid HTTP response line received');
        }
        
        $response = new HttpResponse();
        $response = $response->withProtocolVersion($m[1]);
        $response = $response->withStatus((int) $m[2], \trim($m[3]));
        
        $response = yield from $this->parseHeaders($stream, $response);
        
        if ($dropBody || Http::isResponseWithoutBody($response->getStatusCode())) {
            $body = new StringBody();
        } else {
            $body = Body::fromMessage($stream, $response);
        }
        
        static $remove = [
            'Content-Encoding',
            'Trailer',
        ];
        
        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }
        
        return $response->withBody($body);
    }
}
