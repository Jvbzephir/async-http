<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Awaitable;
use KoolKode\Async\CopyBytes;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Stream\DuplexStream;

class Connector
{
    protected $parser;

    public function __construct(ResponseParser $parser = null)
    {
        $this->parser = $parser ?? new ResponseParser();
    }

    public function send(DuplexStream $stream, HttpRequest $request): Awaitable
    {
        return new Coroutine(function () use ($stream, $request) {
            try {
                yield from $this->sendRequest($stream, $request);
                
                $response = yield from $this->parser->parseResponse($stream, $request->getMethod() === Http::HEAD);
                
                return $response;
            } catch (\Throwable $e) {
                $stream->close();
                
                throw $e;
            }
        });
    }

    protected function sendRequest(DuplexStream $stream, HttpRequest $request): \Generator
    {
        static $compression;
        
        if ($compression === null) {
            $compression = \function_exists('inflate_init');
        }
        
        $body = $request->getBody();
        $size = yield $body->getSize();
        $nobody = ($request->getMethod() === Http::HEAD);
        
        $bodyStream = yield $body->getReadableStream();
        
        $buffer = \sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());
        
        $buffer .= "Connection: close\r\n";
        
        if (!$nobody) {
            // FIXME: HTTP/1.0 requires content length...
            if ($size === null) {
                $buffer .= "Transfer-Encoding: chunked\r\n";
            } else {
                $buffer .= "Content-Length: $size\r\n";
            }
        }
        
        if ($compression) {
            $buffer .= "Accept-Encoding: gzip, deflate\r\n";
        }
        
        foreach ($request->getHeaders() as $name => $header) {
            $name = Http::normalizeHeaderName($name);
            
            foreach ($header as $value) {
                $buffer .= $name . ': ' . $value . "\r\n";
            }
        }
        
        yield $stream->write($buffer . "\r\n");
        yield $stream->flush();
        
        // TODO: Add support for Expect: 100-continue
        
        if ($nobody) {
            $bodyStream->close();
        } else {
            if ($size === null) {
                // Align each chunk with length and line breaks to fit into 4 KB payload.
                yield new CopyBytes($bodyStream, $stream, true, null, 4089, function (string $chunk) {
                    return \dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n";
                });
                
                yield $stream->write("0\r\n\r\n");
            } else {
                yield new CopyBytes($bodyStream, $stream, true, $size);
            }
            
            yield $stream->flush();
        }
    }

    protected function normalizeRequest(HttpRequest $request): HttpRequest
    {
        static $remove = [
            'Accept-Encoding',
            'Connection',
            'Content-Encoding',
            'Content-Length',
            'Keep-Alive',
            'Trailer',
            'Transfer-Encoding'
        ];
        
        $request = $request->withProtocolVersion('1.1');
        
        foreach ($remove as $name) {
            $request = $request->withoutHeader($name);
        }
        
        return $request->withHeader('Date', \gmdate(Http::DATE_RFC1123));
    }
}
