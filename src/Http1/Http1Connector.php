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
use KoolKode\Async\Concurrent\Executor;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Socket\ClientEncryption;
use KoolKode\Async\Socket\ClientFactory;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableMemoryStream;

class Http1Connector implements HttpConnector
{
    protected $keepAlive = true;
    
    protected $maxLifetime = 30;
    
    protected $connections = [];
    
    protected $pending = [];
    
    public function getPriority(): int
    {
        return 11;
    }

    public function isRequestSupported(HttpRequest $request): bool
    {
        switch ($request->getProtocolVersion()) {
            case '1.0':
            case '1.1':
                return true;
        }
        
        return false;
    }

    public function isConnected(string $key): bool
    {
        return isset($this->pending[$key]);
    }

    public function getProtocols(): array
    {
        return [
            'http/1.1'
        ];
    }
    
    public function isSupported(string $protocol): bool
    {
        switch ($protocol) {
            case 'http/1.1':
            case '':
                return true;
        }
        
        return false;
    }
    
    public function send(Context $context, HttpRequest $request, ?DuplexStream $stream = null): Promise
    {
        $token = $context->cancellationToken();
        $context = $context->shield();
        
        $uri = $request->getUri();
        $key = $uri->getScheme() . '://' . $uri->getHostWithPort(true);
        
        if ($stream) {
            $conn = new ClientConnection($key, $stream);
        } elseif (isset($this->connections[$key])) {
            $conn = $this->connections[$key]->dequeue();
            
            if ($this->connections[$key]->isEmpty()) {
                unset($this->connections[$key]);
            }
        } else {
            $conn = null;
        }
        
        if (empty($this->pending[$key])) {
            $this->pending[$key] = new Executor(8);
        }
        
        return $this->pending[$key]->submit($context, $this->processRequest($context, $request, $conn, $token));
    }
    
    protected function connect(Context $context, HttpRequest $request): \Generator
    {
        $uri = $request->getUri();
        $key = $uri->getScheme() . '://' . $uri->getHostWithPort(true);
        
        $tls = null;
        
        if ($uri->getScheme() == 'https') {
            $tls = new ClientEncryption();
            $tls = $tls->withPeerName($uri->getHostWithPort());
            $tls = $tls->withAlpnProtocols(...$this->getProtocols());
        }
        
        $factory = new ClientFactory('tcp://' . $uri->getHostWithPort(true), $tls);
        
        return new ClientConnection($key, yield $factory->connect($context));
    }

    protected function processRequest(Context $context, HttpRequest $request, ?ClientConnection $conn, CancellationToken $token): \Generator
    {
        if ($conn === null) {
            $conn = yield from $this->connect($context, $request);
        }
        
        try {
            $request = $this->normalizeRequest($request);
            
            $token->throwIfCancelled();
            $token->throwIfCancelled(yield from $this->sendRequest($context, $request, $conn->stream));
            
            $response = $this->parseResponseHeaders($token->throwIfCancelled(yield $conn->stream->readTo($context, "\r\n\r\n")));
            $close = $this->shouldConnectionBeClosed($response);
            
            if ('' !== ($len = $response->getHeaderLine('Content-Length'))) {
                $stream = new LimitStream($conn->stream, (int) $len, $close);
            } elseif ('chunked' == $response->getHeaderLine('Transfer-Encoding')) {
                $stream = new ChunkDecodedStream($conn->stream, $close);
            } elseif (!$this->keepAlive) {
                $stream = $conn->stream;
            } else {
                $stream = new ReadableMemoryStream();
            }
            
            $defer = new Deferred($context);
            $body = new StreamBody(new EntityStream($stream, true, $defer));
            
            $response = $response->withoutHeader('Content-Length');
            $response = $response->withoutHeader('Transfer-Encoding');
            $response = $response->withBody($body);
            
            $defer->promise()->when(function ($e, ?bool $done) use ($context, $body, $conn, $close) {
                if ($done) {
                    return $this->release($conn, $close);
                }
                
                $body->discard($context->unreference())->when(function () use ($conn, $close) {
                    $this->release($conn, $close);
                });
            });
        } catch (\Throwable $e) {
            $this->release($conn, true);
            
            throw $e;
        }
        
        return $response;
    }
    
    protected function shouldConnectionBeClosed(HttpResponse $response): bool
    {
        if (!$this->keepAlive) {
            return true;
        }
        
        if ($response->getProtocolVersion() === '1.0' && !\in_array('keep-alive', $response->getHeaderTokenValues('Connection'), true)) {
            return true;
        }
        
        if (!$response->hasHeader('Content-Length') && 'chunked' !== \strtolower($response->getHeaderLine('Transfer-Encoding'))) {
            return true;
        }
        
        return false;
    }

    protected function release(ClientConnection $conn, bool $dispose)
    {
        $conn->remaining--;
        $conn->expires = \time() + 30;
        
        if ($dispose) {
            return $conn->close();
        }
        
        if (empty($this->connections[$conn->key])) {
            $this->connections[$conn->key] = new \SplQueue();
        }
        
        $this->connections[$conn->key]->enqueue($conn);
    }

    protected function sendRequest(Context $context, HttpRequest $request, DuplexStream $stream): \Generator
    {
        $body = $request->getBody();
        $size = yield $body->getSize($context);
        
        $bodyStream = yield $body->getReadableStream($context);
        
        try {
            $buffer = $this->serializeHeaders($request, $size);
            
            $chunk = yield $bodyStream->readBuffer($context, 8192, false);
            $len = \strlen($chunk ?? '');
            
            if ($chunk === null) {
                $size = 0;
            } elseif ($len < 8192) {
                $size = $len;
            }
            
            $sent = yield $stream->write($context, $buffer . "\r\n");
            
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
            $buffer .= \sprintf("Keep-Alive: timeout=%u\r\n", $this->maxLifetime);
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
