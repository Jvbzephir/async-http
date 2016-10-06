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

use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\ReadableStream;

class ResponseParser
{
    public function parseResponse(ReadableStream $stream): \Generator
    {
        $line = yield $stream->readLine();
        $parts = \array_map('trim', \preg_split("'\s+'", $line, 3));
        
        $version = $parts[0];
        $status = (int) $parts[1];
        $reason = $parts[2] ?? '';
        
        $response = new HttpResponse();
        $response = $response->withProtocolVersion(\substr($version, -3));
        $response = $response->withStatus($status, $reason);
        
        while (NULL !== ($line = yield $stream->readLine())) {
            if (\trim($line) === '') {
                break;
            }
            
            $parts = \explode(':', $line, 2);
            
            $response = $response->withAddedHeader(\trim($parts[0]), \trim($parts[1]));
        }
        
        $body = Body::fromMessage($stream, $response);
        
        static $remove = [
            'Connection',
            'Content-Encoding',
            'Content-Length',
            'Keep-Alive',
            'Trailer',
            'Transfer-Encoding'
        ];
        
        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }
        
        return $response->withBody($body);
    }
}
