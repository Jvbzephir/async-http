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

class RequestParser extends MessageParser
{
    public function parseRequest(ReadableStream $stream): \Generator
    {
        $line = yield $stream->readLine();
        $parts = \preg_split("'\s+'", \trim((string) $line), 3);
        
        if (\count($parts) !== 3) {
            throw new \RuntimeException('Invalid HTTP request received');
        }
        
        if ($parts[2] !== 'HTTP/1.0' && $parts[2] !== 'HTTP/1.1') {
            throw new \RuntimeException('Invalid HTTP version');
        }
        
        $request = new HttpRequest($parts[1], $parts[0], [], \substr($parts[2], -3));
        
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
