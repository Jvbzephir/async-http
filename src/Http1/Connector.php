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

use KoolKode\Async\CancellationToken;
use KoolKode\Async\Context;
use KoolKode\Async\Deferred;
use KoolKode\Async\Promise;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Socket\ClientEncryption;
use KoolKode\Async\Socket\ClientFactory;
use KoolKode\Async\Socket\Socket;

class Connector
{
    protected $keepAlive = false;
    
    public function send(Context $context, HttpRequest $request): Promise
    {
        $request = $this->normalizeRequest($request);
        
        $token = $context->cancellationToken();
        $context = $context->shield();
        
        return $context->task($this->processRequest($context, $request, $token));
    }

    protected function processRequest(Context $context, HttpRequest $request, CancellationToken $token): \Generator
    {
        $uri = $request->getUri();
        $tls = null;
        
        if ($uri->getScheme() == 'https') {
            $tls = new ClientEncryption();
            $tls = $tls->withPeerName($uri->getHostWithPort());
            $tls = $tls->withAlpnProtocols('http/1.1');
        }
        
        $factory = new ClientFactory('tcp://' . $uri->getHostWithPort(true), $tls);
        $socket = yield $factory->connect($context);
        
        try {
            $token->throwIfCancelled();
            $token->throwIfCancelled(yield from $this->sendRequest($context, $request, $socket));
            
            $buffer = $token->throwIfCancelled(yield $socket->readTo($context, "\r\n\r\n"));
            $response = $this->parseResponseHeaders($buffer);
        } catch (\Throwable $e) {
            $socket->close();
            
            throw $e;
        }
        
        if ('' !== ($len = $response->getHeaderLine('Content-Length'))) {
            $stream = new LimitStream($socket, (int) $len);
        } elseif ('chunked' == $response->getHeaderLine('Transfer-Encoding')) {
            $stream = new ChunkDecodedStream($socket);
        } elseif (!$this->keepAlive) {
            $stream = new StreamBody($socket);
        } else {
            $stream = $socket;
        }
        
        $defer = new Deferred($context);
        
        $response = $response->withoutHeader('Content-Length');
        $response = $response->withoutHeader('Transfer-Encoding');
        $response = $response->withBody($body = new StreamBody(new EntityStream($stream, true, $defer)));
        
        $context = $context->shield()->unreference();
        
        $defer->promise()->when(function ($e, ?bool $done) use ($context, $body, $socket) {
            if ($done) {
                $this->releaseSocket($socket);
            } else {
                $context->task(function (Context $context) use ($body, $socket) {
                    try {
                        yield $body->discard($context);
                    } finally {
                        $this->releaseSocket($socket);
                    }
                });
            }
        });
        
        return $response;
    }
    
    protected function sendRequest(Context $context, HttpRequest $request, Socket $socket): \Generator
    {
        $body = $request->getBody();
        $size = yield $body->getSize($context);
        
        $stream = yield $body->getReadableStream($context);
        
        try {
            $buffer = $this->serializeHeaders($request, $size);
            
            $chunk = yield $stream->readBuffer($context, 8192, false);
            $len = \strlen($chunk ?? '');
            
            if ($chunk === null) {
                $size = 0;
            } elseif ($len < 8192) {
                $size = $len;
            }
            
            $sent = yield $socket->write($context, $buffer . "\r\n");
            
            if ($size === null) {
                do {
                    $sent += yield $socket->write($context, \dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n");
                } while (null !== ($chunk = yield $stream->read($context)));
                
                yield $socket->write($context, "0\r\n\r\n");
            } elseif ($size > 0) {
                do {
                    $sent += yield $socket->write($context, $chunk);
                } while (null !== ($chunk = yield $stream->read($context)));
            }
        } finally {
            $stream->close();
        }
        
        return $sent;
    }
    
    protected function releaseSocket(Socket $socket)
    {
        // TODO: Release socket to keep-alive pool.
    }

    protected function normalizeRequest(HttpRequest $request): HttpRequest
    {
        static $remove = [
            'Content-Length',
            'Expect',
            'Keep-Alive',
            'TE',
            'Trailer',
            'Transfer-Encoding'
        ];
        
        $version = $request->getProtocolVersion();
        
        switch ($version) {
            case '1.0':
            case '1.1':
                // Everything fine, version is supported.
                break;
            default:
                $request = $request->withProtocolVersion('1.1');
        }
        
        $tokens = [];
        
        foreach ($request->getHeaderTokenValues('Connection') as $token) {
            if ($token !== 'close' && $token !== 'keep-alive') {
                $tokens[] = $token;
            }
        }
        
        if (empty($tokens)) {
            $request = $request->withoutHeader('Connection');
        } else {
            $request = $request->withHeader('Connection', \implode(', ', $tokens));
        }
        
        foreach ($remove as $name) {
            $request = $request->withoutHeader($name);
        }
        
        return $request->withHeader('Date', \gmdate(Http::DATE_RFC1123));
    }

    protected function serializeHeaders(HttpRequest $request, ?int $size): string
    {
        if (\in_array('upgrade', $request->getHeaderTokenValues('Connection'))) {
            $request = $request->withHeader('Connection', 'upgrade');
        } else {
            $request = $request->withHeader('Connection', $this->keepAlive ? 'keep-alive' : 'close');
        }
        
        $buffer = \sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());
        
        if ($this->keepAlive) {
            $buffer .= \sprintf("Keep-Alive: timeout=%u\r\n", $this->pool->getMaxLifetime());
        }
        
        if ($size === null) {
            $buffer .= "Transfer-Encoding: chunked\r\n";
        } else {
            $buffer .= "Content-Length: $size\r\n";
        }
        
        foreach ($request->getHeaders() as $name => $header) {
            $name = \ucwords($name, '-');
            
            foreach ($header as $value) {
                $buffer .= $name . ': ' . $value . "\r\n";
            }
        }
        
        return $buffer;
    }
    
    protected function parseResponseHeaders(string $buffer): HttpResponse
    {
        $lines = \explode("\n", $buffer);
        $m = null;
        
        if (!\preg_match("'^HTTP/(1\\.[01])\s+([0-9]+)(\s+.*)?$'i", \trim($lines[0]), $m)) {
            throw new \RuntimeException('Invalid HTTP response line received');
        }
        
        $version = $m[1];
        $status = (int) $m[2];
        $reason = \trim($m[3]);
        $headers = [];
        
        for ($count = \count($lines), $i = 1; $i < $count; $i++) {
            list ($k, $v) = \explode(':', $lines[$i], 2);
            $k = \trim($k);
            
            $headers[$k][] = $v;
        }
        
        $response = new HttpResponse($status, $headers, null, $version);
        $response = $response->withReason($reason);
        
        return $response;
    }
}
