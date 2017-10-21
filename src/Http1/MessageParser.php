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

use KoolKode\Async\Context;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpMessage;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\StreamClosedException;

class MessageParser
{
    public function parseRequest(Context $context, ReadableStream $stream): \Generator
    {
        $lines = yield $stream->readTo($context, "\r\n\r\n");
        
        if ($lines === null) {
            throw new StreamClosedException('Stream closed before request line was read');
        }
        
        $lines = \explode("\n", \ltrim($lines));
        $m = null;
        
        if (!\preg_match("'^(\S+)\s+?(\S+)\s+?HTTP/(1\\.[01])$'iU", \trim($lines[0]), $m)) {
            throw new \RuntimeException('Invalid HTTP request line received');
        }
        
        $method = $m[1];
        $target = $m[2];
        $version = $m[3];
        
        $request = new HttpRequest($target, $method, $this->parseHeaders(\array_slice($lines, 1)), null, $version);
        $request = $request->withRequestTarget($target);
        
        if ($target != '*' && $request->hasHeader('Host', false)) {
            $host = $request->getHeaderLine('Host');
            
            if (\preg_match("'^[^:]+://'", $target)) {
                $request = $request->withUri(Uri::parse('http://' . $host));
            } else {
                $request = $request->withUri($request->getUri()->withScheme('http')->withHost($host));
            }
        }
        
        return $request;
    }

    public function parseResponse(Context $context, ReadableStream $stream): \Generator
    {
        do {
            $line = yield $stream->readLine($context);
            
            if ($line === null) {
                throw new StreamClosedException('Stream closed before response line was read');
            }
        } while ($line === '');
        
        $m = null;
        
        if (!\preg_match("'^HTTP/(1\\.[01])\s+?([0-9]+)(\s+?.*)?$'iU", \trim($line), $m)) {
            throw new \RuntimeException('Invalid HTTP response line received');
        }
        
        $version = $m[1];
        $status = (int) $m[2];
        $reason = \trim($m[3] ?? '');
        
        if ($status == Http::CONTINUE) {
            return new HttpResponse($status, [], null, $version);
        }
        
        $lines = yield $stream->readTo($context, "\r\n\r\n");
        
        if ($lines === null) {
            throw new StreamClosedException('Stream closed before response line was read');
        }
        
        $lines = \explode("\n", \ltrim($lines));
        
        $response = new HttpResponse($status, $this->parseHeaders($lines), null, $version);
        $response = $response->withReason($reason);
        
        return $response;
    }

    public function parseBodyStream(HttpMessage $message, ReadableStream $stream, bool $close = true): ReadableStream
    {
        if ('' !== ($len = $message->getHeaderLine('Content-Length'))) {
            return new LimitStream($stream, (int) $len, $close);
        }
        
        if ('chunked' == $message->getHeaderLine('Transfer-Encoding')) {
            return new ChunkDecodedStream($stream, $close);
        }
        
        return $close ? $stream : new ReadableMemoryStream();
    }

    protected function parseHeaders(array $lines): array
    {
        $headers = [];
        
        foreach ($lines as $line) {
            if (false === ($pos = \strpos($line, ':'))) {
                throw new StreamClosedException('Stream closed before headers were read');
            }
            
            $headers[\substr($line, 0, $pos)][] = \substr($line, $pos + 1);
        }
        
        return $headers;
    }
}
