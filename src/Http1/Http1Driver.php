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

use KoolKode\Async\CancellationException;
use KoolKode\Async\Context;
use KoolKode\Async\Promise;
use KoolKode\Async\Filesystem\FilesystemProxy;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpDriver;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\ContinuationBody;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\StreamClosedException;

class Http1Driver implements HttpDriver
{
    /**
     * Maximum number of HTTP requests to be received over a single connection.
     * 
     * @var int
     */
    protected $keepAlive = 100;
    
    /**
     * Max idle time between multiple HTTP requests over the same connection (in seconds).
     * 
     * @var int
     */
    protected $maxIdleTime = 30;
    
    /**
     * HTTP message parser being used to decode incoming HTTP requests.
     * 
     * @var MessageParser
     */
    protected $parser;
    
    protected $filesystem;
    
    /**
     * Registered HTTP upgrade handlers that implement a connection update based on a value returned from an HTTP handler.
     * 
     * @var array
     */
    protected $upgradeResultHandlers = [];
    
    public function __construct(?MessageParser $parser = null)
    {
        $this->parser = $parser ?? new MessageParser();
        $this->filesystem = new FilesystemProxy();
    }
    
    public function withKeepAlive(int $keepAlive): self
    {
        if ($keepAlive < 0) {
            throw new \InvalidArgumentException('Number of dispatchable keep-alive HTTP requests must not be negative');
        }
        
        $driver = clone $this;
        $driver->keepAlive = $keepAlive;
        
        return $driver;
    }

    public function withMaxIdleTime(int $idle): self
    {
        if ($idle < 1) {
            throw new \InvalidArgumentException('Max idle time between HTTP requests must not be less than 1 second');
        }
        
        $driver = clone $this;
        $driver->maxIdleTime = $idle;
        
        return $driver;
    }

    public function withUpgradeResultHandler(UpgradeResultHandler $handler): self
    {
        $driver = clone $this;
        $driver->upgradeResultHandlers[] = $handler;
        
        return $driver;
    }
    
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
            $remaining = $this->keepAlive;
            
            try {
                do {
                    try {
                        $ctx = $this->keepAlive ? $context->cancelAfter($this->maxIdleTime * 1000) : $context;
                        $request = yield from $this->parser->parseRequest($ctx, $stream);
                    } catch (CancellationException $e) {
                        return;
                    }
                    
                    try {
                        $close = $this->shouldConnectionBeClosed($request);
                        $body = $this->parser->parseBodyStream($request, $stream, false);
                        
                        $request = $request->withoutHeader('Content-Length');
                        $request = $request->withoutHeader('Transfer-Encoding');
                        
                        if (\in_array('100-continue', $request->getHeaderTokenValues('Expect'), true)) {
                            $request = $request->withBody(new ContinuationBody($body, function (Context $context, $result) use ($stream) {
                                yield $stream->write($context, Http::getStatusLine(Http::CONTINUE) . "\r\n\r\n");
                                
                                return $result;
                            }));
                        } else {
                            $request = $request->withBody(new StreamBody($body));
                        }
                        
                        $upgrade = null;
                        
                        $response = $action($context, $request, function (Context $context, $response) use ($request, & $upgrade) {
                            if ($response instanceof \Generator) {
                                $response = yield from $response;
                            }
                            
                            return $this->respond($request, $response, $upgrade);
                        });
                        
                        if ($response instanceof \Generator) {
                            $response = yield from $response;
                        }
                        
                        if ($upgrade && $response->getStatusCode() !== Http::SWITCHING_PROTOCOLS) {
                            $upgrade = null;
                        }
                        
                        if ($request->getMethod() == Http::HEAD || Http::isResponseWithoutBody($response->getStatusCode())) {
                            yield $response->getBody()->discard($context);
                            
                            $response = $response->withBody(new StringBody());
                        }
                        
                        $response = $this->normalizeResponse($request, $response);
                        $response = $response->withHeader('Connection', $upgrade ? 'upgrade' : ($close ? 'close' : 'keep-alive'));
                        
                        if (!$upgrade && !$close) {
                            $response = $response->withHeader('Keep-Alive', \sprintf('timeout=%u, max=%u', $this->maxIdleTime, --$remaining));
                        }
                        
                        yield from $this->sendRespone($context, $request, $response, $stream);
                        
                        if ($upgrade) {
                            yield from $upgrade->upgradeConnection($context, $stream, $request, $response);
                            
                            break;
                        }
                    } catch (\Throwable $e) {
                        $stream->close($e);
                        
                        throw $e;
                    }
                } while (!$close && $remaining > 0);
            } catch (StreamClosedException $e) {
                // Client disconnected.
            } finally {
                $stream->close();
            }
        });
    }

    protected function respond(HttpRequest $request, $response, & $upgrade = null): HttpResponse
    {
        if ($response instanceof HttpResponse) {
            return $response;
        }
        
        if (\in_array('upgrade', $request->getHeaderTokenValues('Connection'), true)) {
            $protocols = $request->getHeaderTokenValues('Upgrade', true);
            
            foreach ($protocols as $protocol) {
                foreach ($this->upgradeResultHandlers as $handler) {
                    if ($handler->isUpgradeSupported($protocol, $request, $response)) {
                        $upgrade = $handler;
                        
                        return $handler->createUpgradeResponse($request, $response);
                    }
                }
            }
        }
        
        return new HttpResponse(Http::INTERNAL_SERVER_ERROR);
    }
    
    protected function shouldConnectionBeClosed(HttpRequest $request): bool
    {
        if ($this->keepAlive === 0) {
            return true;
        }
        
        $conn = $request->getHeaderTokenValues('Connection');
        
        if ($request->getProtocolVersion() == '1.0') {
            if (!\in_array('keep-alive', $conn, true) || \in_array('upgrade', $conn, true)) {
                return true;
            }
        } else {
            foreach ($conn as $token) {
                switch ($token) {
                    case 'close':
                    case 'upgrade':
                        return true;
                }
            }
        }
        
        return false;
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
        
        if (Http::isResponseWithoutBody($response->getStatusCode())) {
            yield $body->discard($context);
            
            $body = new StringBody();
            $bodyStream = yield $body->getReadableStream($context);
        } else {
            $bodyStream = yield $body->getReadableStream($context);
        }
        
        try {
            $chunk = yield $bodyStream->readBuffer($context, 0x7FFF, false);
            $len = \strlen($chunk ?? '');
            
            if ($chunk === null) {
                $size = 0;
            } elseif ($len < 0x7FFF) {
                $size = $len;
            } else {
                $size = yield $body->getSize($context);
            }
            
            yield $request->getBody()->discard($context);
            
            if ($size === null && $request->getProtocolVersion() == '1.0') {
                $temp = yield $this->filesystem->tempStream($context);
                
                try {
                    do {
                        yield $temp->write($context, $chunk);
                    } while (null !== ($chunk = yield $bodyStream->read($context)));
                } finally {
                    $temp->close();
                }
                
                $size = yield $temp->size($context);
                $bodyStream = yield $temp->readStream($context);
                $chunk = yield $bodyStream->read($context);
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
        
        if ($reason === '') {
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
