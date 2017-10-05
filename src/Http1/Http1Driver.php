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
use KoolKode\Async\Stream\StreamClosedException;

class Http1Driver implements HttpDriver
{
    protected $parser;
    
    protected $upgradeResultHandlers = [];
    
    public function __construct(?MessageParser $parser = null)
    {
        $this->parser = $parser ?? new MessageParser();
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
            try {
                try {
                    $request = yield from $this->parser->parseRequest($context, $stream);
                } catch (StreamClosedException $e) {
                    return;
                }
                
                $body = $this->parser->parseBodyStream($request, $stream, false);
                
                $request = $request->withoutHeader('Content-Length');
                $request = $request->withoutHeader('Transfer-Encoding');
                $request = $request->withBody($body = new StreamBody($body));
                
                $upgrade = null;
                
                $response = $action($context, $request, function (Context $context, $response) use ($request, & $upgrade) {
                    if ($response instanceof \Generator) {
                        $response = yield from $response;
                    }
                    
                    if (!$response instanceof HttpResponse) {
                        if (\in_array('upgrade', $request->getHeaderTokenValues('Connection'), true)) {
                            $protocols = $request->getHeaderTokenValues('Upgrade', true);
                            
                            foreach ($protocols as $protocol) {
                                foreach ($this->upgradeResultHandlers as $handler) {
                                    if ($handler->isUpgradeSupported($protocol, $request, $response)) {
                                        $response = $handler->createUpgradeResponse($request, $response);
                                        $upgrade = $handler;
                                        
                                        break 2;
                                    }
                                }
                            }
                        }
                        
                        if (!$response instanceof HttpResponse) {
                            $response = new HttpResponse(Http::INTERNAL_SERVER_ERROR);
                        }
                    }
                    
                    return $response;
                });
                
                if ($response instanceof \Generator) {
                    $response = yield from $response;
                }
                
                yield $body->discard($context);
                
                $response = $this->normalizeResponse($request, $response);
                $response = $response->withHeader('Connection', $upgrade ? 'upgrade' : 'close');
                
                yield from $this->sendRespone($context, $request, $response, $stream);
                
                if ($upgrade) {
                    yield from $upgrade->upgradeConnection($context, $stream, $request, $response);
                }
            } finally {
                $stream->close();
            }
        });
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
