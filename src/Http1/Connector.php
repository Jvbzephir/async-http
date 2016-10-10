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

use KoolKode\Async\Atomic;
use KoolKode\Async\Awaitable;
use KoolKode\Async\CopyBytes;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Loop\LoopConfig;
use KoolKode\Async\Stream\DuplexStream;

class Connector implements HttpConnector
{
    protected $parser;
    
    protected $debug = false;

    public function __construct(ResponseParser $parser = null)
    {
        $this->parser = $parser ?? new ResponseParser();
    }
    
    public function setDebug(bool $debug)
    {
        $this->debug = $debug;
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
    public function isSupported(string $protocol, array $meta = []): bool
    {
        return \in_array($protocol, [
            'http/1.1',
            ''
        ], true);
    }
    
    /**
     * {@inheritdoc}
     */
    public function send(DuplexStream $stream, HttpRequest $request, bool $keepAlive = true): Awaitable
    {
        return new Coroutine(function () use ($stream, $request, $keepAlive) {
            $done = false;
            
            try {
                // Ensure no partial requests are being sent.
                yield new Atomic(new Coroutine($this->sendRequest($stream, $request, $done, $keepAlive)));
                
                $response = yield from $this->parser->parseResponse($stream, $request->getMethod() === Http::HEAD);
                
                if ($keepAlive && !$this->shouldConnectionBeClosed($response)) {
                    $response->getBody()->setCascadeClose(false);
                }
                
                $response = $response->withoutHeader('Content-Length');
                $response = $response->withoutHeader('Transfer-Encoding');
                
                return $response;
            } catch (\Throwable $e) {
                if ($done) {
                    $stream->close();
                } else {
                    $done = true;
                }
                
                throw $e;
            }
        });
    }

    protected function shouldConnectionBeClosed(HttpResponse $response): bool
    {
        if (!\in_array('keep-alive', $response->getHeaderTokens('Connection'), true)) {
            return true;
        }
        
        if (!$response->hasHeader('Content-Length') && 'chunked' !== \strtolower($response->getHeaderLine('Transfer-Encoding'))) {
            return true;
        }
        
        return false;
    }
    
    protected function sendRequest(DuplexStream $stream, HttpRequest $request, bool & $done, bool $keepAlive): \Generator
    {
        static $compression;
        
        if ($compression === null) {
            $compression = \function_exists('inflate_init');
        }
        
        try {
            $request = $this->normalizeRequest($request);
            $body = $request->getBody();
            $size = yield $body->getSize();
            $nobody = ($request->getMethod() === Http::HEAD);
            
            $bodyStream = yield $body->getReadableStream();
            
            $buffer = \sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());
            
            if ($keepAlive) {
                $buffer .= "Connection: keep-alive\r\n";
            } else {
                $buffer .= "Connection: close\r\n";
            }
            
            if (!$nobody) {
                if ($request->getProtocolVersion() == '1.0' && $size === null) {
                    $tmp = yield LoopConfig::currentFilesystem()->tempStream();
                    
                    try {
                        $size = yield new CopyBytes($bodyStream, $tmp);
                    } catch (\Throwable $e) {
                        $tmp->close();
                        
                        throw $e;
                    }
                    
                    $bodyStream = $tmp;
                }
                
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
        } finally {
            if ($done) {
                $stream->close();
            }
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
        
        $version = $request->getProtocolVersion();
        
        switch ($version) {
            case '1.0':
            case '1.1':
                // Everything fine, version is supported.
                break;
            default:
                $request = $request->withProtocolVersion('1.1');
        }
        
        foreach ($remove as $name) {
            $request = $request->withoutHeader($name);
        }
        
        return $request->withHeader('Date', \gmdate(Http::DATE_RFC1123));
    }
}
