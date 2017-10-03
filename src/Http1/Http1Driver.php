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
use KoolKode\Async\Promise;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpDriver;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableMemoryStream;

class Http1Driver implements HttpDriver
{
    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 11;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getProtocols(): array
    {
        return [
            'http/1.1'
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function isSupported(string $protocol): bool
    {
        switch ($protocol) {
            case 'http/1.1':
            case '':
                return true;
        }
        
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function listen(Context $context, DuplexStream $stream, callable $action): Promise
    {
        return $context->task(function (Context $context) use ($stream, $action) {
            try {
                if (null === ($headers = yield $stream->readTo($context, "\r\n\r\n"))) {
                    return;
                }
                
                $request = $this->parseRequestHeaders($headers);
                
                if ('' !== ($len = $request->getHeaderLine('Content-Length'))) {
                    $body = new LimitStream($stream, (int) $len, false);
                } elseif ('chunked' == $request->getHeaderLine('Transfer-Encoding')) {
                    $body = new ChunkDecodedStream($stream, false);
                } else {
                    $body = new ReadableMemoryStream();
                }
                
                $request = $request->withoutHeader('Content-Length');
                $request = $request->withoutHeader('Transfer-Encoding');
                $request = $request->withBody($body = new StreamBody($body));
                
                $response = $action($context, $request);
                
                if ($response instanceof \Generator) {
                    $response = yield from $response;
                }
                
                yield $body->discard($context);
                
                $response = $this->normalizeResponse($request, $response);
                $response = $response->withHeader('Connection', 'close');
                
                yield from $this->sendRespone($context, $request, $response, $stream);
            } finally {
                $stream->close();
            }
        });
    }
    
    protected function parseRequestHeaders(string $buffer): HttpRequest
    {
        $lines = \explode("\n", $buffer);
        $m = null;
        
        if (!\preg_match("'^(\S+)\s+?(\S+)\s+?HTTP/(1\\.[01])$'iU", \trim($lines[0]), $m)) {
            throw new \RuntimeException('Invalid HTTP request line received');
        }
        
        $method = $m[1];
        $target = $m[2];
        $version = $m[3];
        $headers = [];
        
        for ($count = \count($lines), $i = 1; $i < $count; $i++) {
            list ($k, $v) = \explode(':', $lines[$i], 2);
            $k = \trim($k);
            
            $headers[$k][] = $v;
        }
        
        if ($target == '*') {
            $uri = 'http://localhost/';
        } elseif ('/' === ($target[0] ?? null)) {
            $uri = 'http://localhost' . $target;
        } else {
            $uri = $target;
        }
        
        $request = new HttpRequest($uri, $method, $headers, null, $version);
        $request = $request->withRequestTarget($target);
        
        return $request;
    }
    
    protected function normalizeResponse(HttpRequest $request, HttpResponse $response): HttpResponse
    {
        static $remove = [
            'Content-Length',
            'Keep-Alive',
            'TE',
            'Trailer',
            'Transfer-Encoding'
        ];
        
        $response = $response->withProtocolVersion($request->getProtocolVersion());
        
        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }
        
        return $response->withHeader('Date', \gmdate(Http::DATE_RFC1123));
    }

    protected function sendRespone(Context $context, HttpRequest $request, HttpResponse $response, DuplexStream $stream): \Generator
    {
        $body = $response->getBody();
        $size = yield $body->getSize($context);
        
        if (Http::isResponseWithoutBody($response->getStatusCode())) {
            yield $body->discard($context);
            
            $body = new StringBody();
            $bodyStream = yield $body->getReadableStream($context);
        } else {
            $bodyStream = yield $body->getReadableStream($context);
        }
        
        try {
            $chunk = yield $bodyStream->readBuffer($context, 8192, false);
            $len = \strlen($chunk ?? '');
            
            if ($chunk === null) {
                $size = 0;
            } elseif ($len < 8192) {
                $size = $len;
            }
            
            $sent = yield $stream->write($context, $this->serializeHeaders($response, $size) . "\r\n");
            
            if ($size === null) {
                do {
                    $sent += yield $stream->write($context, \dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n");
                } while (null !== ($chunk = yield $bodyStream->read($context)));
                
                yield $stream->write($context, "0\r\n\r\n");
            } elseif ($size > 0) {
                do {
                    $sent += yield $stream->write($context, $chunk);
                } while (null !== ($chunk = yield $bodyStream->read($context)));
            }
        } finally {
            $bodyStream->close();
        }
        
        return $sent;
    }

    protected function serializeHeaders(HttpResponse $response, ?int $size): string
    {
        $reason = $response->getReasonPhrase();
        
        if ($response === '') {
            $reason = Http::getReason($response->getStatusCode());
        }
        
        $buffer = \sprintf("HTTP/%s %s%s\r\n", $response->getProtocolVersion(), $response->getStatusCode(), \rtrim(' ' . $reason));
        
        if ($size === null) {
            $buffer .= "Transfer-Encoding: chunked\r\n";
        } else {
            $buffer .= "Content-Length: $size\r\n";
        }
        
        foreach ($response->getHeaders() as $name => $header) {
            $name = \ucwords($name, '-');
            
            foreach ($header as $value) {
                $buffer .= $name . ': ' . $value . "\r\n";
            }
        }
        
        return $buffer;
    }
}
