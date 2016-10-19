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
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpConnectorContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Loop\LoopConfig;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\ReadableStream;
use Psr\Log\LoggerInterface;

/**
 * Implements the HTTP/1.x protocol on the client side.
 * 
 * @author Martin Schröder
 */
class Connector implements HttpConnector
{
    protected $parser;
    
    protected $conns = [];
    
    protected $keepAlive = true;
    
    protected $expectContinue = true;
    
    protected $pending;
    
    protected $logger;

    public function __construct(ResponseParser $parser = null, LoggerInterface $logger = null)
    {
        $this->parser = $parser ?? new ResponseParser();
        $this->logger = $logger;
        
        $this->pending = new \SplObjectStorage();
    }
    
    public function setKeepAlive(bool $keepAlive)
    {
        $this->keepAlive = $keepAlive;
    }
    
    public function setExpectContinue($expect)
    {
        $this->expectContinue = $expect;
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
    public function shutdown(): Awaitable
    {
        return new Coroutine(function () {
            try {
                foreach ($this->pending as $pending) {
                    foreach ($pending->cancel(new \RuntimeException('HTTP connector closed')) as $task) {
                        yield $task;
                    }
                }
            } finally {
                $this->pending = new \SplObjectStorage();
            }
            
            foreach ($this->conns as $conn) {
                while (!$conn->isEmpty()) {
                    yield $conn->dequeue()->close();
                }
            }
        });
    }
    
    /**
     * {@inheritdoc}
     */
    public function getConnectorContext(Uri $uri): HttpConnectorContext
    {
        $socket = $this->getConnection($uri);
        $context = new HttpConnectorContext();
        
        if ($socket !== null) {
            $context->connected = true;
            $context->stream = $socket;
        }
        
        return $context;
    }
    
    /**
     * {@inheritdoc}
     */
    public function send(HttpConnectorContext $context, HttpRequest $request): Awaitable
    {
        if ($context->stream instanceof PersistentStream) {
            $stream = $context->stream;
        } else {
            $stream = new PersistentStream($context->stream);
            $stream->reference();
        }
        
        $coroutine = new Coroutine(function () use ($stream, $request) {
            $stream->reference();
            
            try {
                $uri = $request->getUri();
                
                $line = yield from $this->sendRequest($stream, $request);
                
                $response = yield from $this->parser->parseResponse($stream, $line, $request->getMethod() === Http::HEAD);
                $body = $response->getBody();
                
                if ($this->logger) {
                    $reason = \rtrim(' ' . $response->getReasonPhrase());
                    
                    if ($reason === '') {
                        $reason = \rtrim(' ' . Http::getReason($response->getStatusCode()));
                    }
                    
                    $this->logger->info(\sprintf('HTTP/%s %03u%s', $response->getProtocolVersion(), $response->getStatusCode(), $reason));
                }
                
                if ($this->shouldConnectionBeClosed($response)) {
                    $stream->close();
                } else {
                    $bodyStream = yield $body->getReadableStream();
                    
                    if ($bodyStream instanceof EntityStream) {
                        $bodyStream->getAwaitable()->when(function () use ($uri, $stream) {
                            $this->releaseConnection($uri, $stream);
                        });
                    } else {
                        $stream->close();
                        
                        $this->releaseConnection($uri, $stream);
                    }
                }
                
                $response = $response->withoutHeader('Content-Length');
                $response = $response->withoutHeader('Transfer-Encoding');
                
                return $response;
            } catch (\Throwable $e) {
                yield $stream->close();
                
                throw $e;
            }
        });
        
        $this->pending->attach($coroutine);
        
        $coroutine->when(function () use ($coroutine) {
            $this->pending->detach($coroutine);
        });
        
        return $coroutine;
    }
    
    protected function getConnection(Uri $uri)
    {
        $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
        
        if (isset($this->conns[$key])) {
            if (!$this->conns[$key]->isEmpty()) {
                if ($this->logger) {
                    $this->logger->debug("Reusing persistent connection: $key");
                }
                
                return $this->conns[$key]->dequeue();
            }
        }
    }

    protected function releaseConnection(Uri $uri, DuplexStream $conn)
    {
        $key = \sprintf('%s://%s', $uri->getScheme(), $uri->getHostWithPort(true));
        
        if (empty($this->conns[$key])) {
            $this->conns[$key] = new \SplQueue();
        }
        
        $this->conns[$key]->enqueue($conn);
        
        if ($this->logger) {
            $this->logger->debug("Released persistent connection: $key");
        }
    }

    protected function shouldConnectionBeClosed(HttpResponse $response): bool
    {
        if (!$this->keepAlive) {
            return true;
        }
        
        if ($response->getProtocolVersion() === '1.0' && !\in_array('keep-alive', $response->getHeaderTokens('Connection'), true)) {
            return true;
        }
        
        if (!$response->hasHeader('Content-Length') && 'chunked' !== \strtolower($response->getHeaderLine('Transfer-Encoding'))) {
            return true;
        }
        
        return false;
    }
    
    protected function sendRequest(PersistentStream $stream, HttpRequest $request): \Generator
    {
        static $compression;
        
        if ($compression === null) {
            $compression = \function_exists('inflate_init');
        }
        
        $request = $this->normalizeRequest($request);
        
        if ($this->logger) {
            $this->logger->info(\sprintf('%s %s HTTP/%s', $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion()));
        }
        
        $body = $request->getBody();
        $size = yield $body->getSize();
        $bodyStream = yield $body->getReadableStream();
        
        $buffer = \sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());
        $buffer .= \sprintf("Connection: %s\r\n", $this->keepAlive ? 'keep-alive' : 'close');
        
        if ($request->getProtocolVersion() == '1.0' && $size === null) {
            $bodyStream = yield from $this->bufferBody($bodyStream, $size);
        }
        
        $clen = ($size === null) ? 4089 : 4096;
        $chunk = yield $bodyStream->readBuffer($clen);
        $len = \strlen($chunk);
        
        if ($chunk === null) {
            $size = 0;
        } elseif ($len < $clen) {
            $size = $len;
        }
        
        if ($size === null) {
            $buffer .= "Transfer-Encoding: chunked\r\n";
        } else {
            $buffer .= "Content-Length: $size\r\n";
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
        
        $expect = false;
        
        if ($this->expectContinue && $chunk !== null && $request->getProtocolVersion() == '1.1') {
            $expect = true;
            $buffer .= "Expect: 100-continue\r\n";
        }
        
        yield $stream->write($buffer . "\r\n");
        yield $stream->flush();
        
        if ($expect) {
            if (!\preg_match("'^HTTP/1\\.1\s+100(?:$|\s)'i", ($line = yield $stream->readLine()))) {
                try {
                    return $line;
                } finally {
                    $bodyStream->close();
                }
            }
        }
        
        if ($size === null) {
            yield $stream->write(\dechex($len) . "\r\n" . $chunk . "\r\n");
            
            if ($len === $clen) {
                // Align each chunk with length and line breaks to fit into 4 KB payload.
                yield new CopyBytes($bodyStream, $stream, true, null, 4089, function (string $chunk) {
                    return \dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n";
                });
            }
            
            yield $stream->write("0\r\n\r\n");
        } elseif ($size > 0) {
            yield $stream->write($chunk);
            
            if ($len === $clen) {
                yield new CopyBytes($bodyStream, $stream, true, $size - $len);
            }
        }
        
        yield $stream->flush();
    }

    protected function normalizeRequest(HttpRequest $request): HttpRequest
    {
        static $remove = [
            'Accept-Encoding',
            'Connection',
            'Content-Encoding',
            'Content-Length',
            'Expect',
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
    
    protected function bufferBody(ReadableStream $stream, int & $size = null): \Generator
    {
        $tmp = yield LoopConfig::currentFilesystem()->tempStream();
        
        try {
            $size = yield new CopyBytes($stream, $tmp);
        } catch (\Throwable $e) {
            $tmp->close();
            
            throw $e;
        }
        
        return $tmp;
    }
}
