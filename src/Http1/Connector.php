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
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Socket\ClientEncryption;
use KoolKode\Async\Socket\ClientFactory;

class Connector
{
    protected $keepAlive = false;
    
    public function send(Context $context, HttpRequest $request): Promise
    {
        return $context->task($this->sendRequest($context, $request));
    }

    protected function sendRequest(Context $context, HttpRequest $request): \Generator
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
            $request = $this->normalizeRequest($request);
            
            $body = $request->getBody();
            $size = yield $body->getSize($context);
            
            $buffer = $this->serializeHeaders($request, $size);
            
            yield $socket->write($context, $buffer . "\r\n");
            
            $response = $this->parseResponseHeaders(yield $socket->readTo($context, "\r\n\r\n"));
            
            if ('' !== ($len = $response->getHeaderLine('Content-Length'))) {
                $response = $response->withoutHeader('Content-Length');
                $response = $response->withBody(new StreamBody(new LimitStream($socket, (int) $len)));
            } elseif ('chunked' == $response->getHeaderLine('Transfer-Encoding')) {
                $response = $response->withoutHeader('Transfer-Encoding');
                $response = $response->withBody(new StreamBody(new ChunkDecodedStream($socket)));
            } elseif (!$this->keepAlive) {
                $response = $response->withBody(new StreamBody($socket));
            }
        } catch (\Throwable $e) {
            $socket->close();
            
            throw $e;
        }
        
        return $response;
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
