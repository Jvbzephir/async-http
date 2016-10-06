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

use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\StreamClosedException;

class RequestParser extends MessageParser
{
    public function parseRequest(ReadableStream $stream): \Generator
    {
        if (null === ($line = yield $stream->readLine())) {
            throw new StreamClosedException('Stream closed before HTTP request line was read');
        }
        
        $m = null;
        
        if (!\preg_match("'^(\S+)\s+(.+)\s+HTTP/(1\\.[01])$'i", trim($line), $m)) {
            throw new StreamClosedException('Invalid HTTP request line received');
        }
        
        $request = new HttpRequest($m[2], $m[1], [], $m[3]);
        
        $request = yield from $this->parseHeaders($stream, $request);
        
        $body = Body::fromMessage($stream, $request);
        
        static $remove = [
            'Content-Encoding',
            'Content-Length',
            'Keep-Alive',
            'Trailer',
            'Transfer-Encoding'
        ];
        
        foreach ($remove as $name) {
            $request = $request->withoutHeader($name);
        }
        
        return $request->withBody($body);
    }
}
