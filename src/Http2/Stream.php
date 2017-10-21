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

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Context;
use KoolKode\Async\Disposable;
use KoolKode\Async\Placeholder;
use KoolKode\Async\Http\ClientSettings;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpBody;
use KoolKode\Async\Http\HttpMessage;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Http\Body\ContinuationBody;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Stream\StreamClosedException;

class Stream implements Disposable
{
    protected $id;
    
    protected $closed = false;
    
    protected $connection;
    
    protected $hpack;
    
    protected $stream;
    
    protected $placeholder;
    
    protected $headers = '';
    
    protected $entity;
    
    protected $inputWindow = Connection::INITIAL_WINDOW_SIZE;
    
    protected $outputWindow;
    
    protected $outputDefer;
    
    public function __construct(Context $context, int $id, Connection $connection, FramedStream $stream, HPack $hpack, int $window)
    {
        $this->id = $id;
        $this->connection = $connection;
        $this->stream = $stream;
        $this->hpack = $hpack;
        $this->outputWindow = $window;
        
        $this->entity = new EntityStream($this->connection, $this->id);
        
        if ($connection->isServer()) {
            $this->placeholder = new Placeholder($context);
        }
    }
    
    public function close(?\Throwable $e = null): void
    {
        if ($this->outputDefer) {
            $this->outputDefer->fail(new StreamClosedException('Stream has been closed', 0, $e));
        }
        
        if (!$this->closed) {
            $this->closed = true;
            $this->connection->closeStream($this->id, true);
        }
    }
    
    public function prepareForReceive(Context $context): void
    {
        $this->placeholder = new Placeholder($context);
    }

    public function receiveRequest(Context $context): \Generator
    {
        try {
            $headers = $this->hpack->decode(yield $context->keepBusy($this->placeholder->promise()));
            
            $scheme = $this->getFirstHeader(':scheme', $headers, 'http');
            $authority = $this->getFirstHeader(':authority', $headers);
            $method = $this->getFirstHeader(':method', $headers, Http::GET);
            $path = $this->getFirstHeader(':path', $headers, '/');
            
            if (\ltrim($path, '/') === '*') {
                $uri = Uri::parse(\sprintf('%s://%s/', $scheme, $authority));
            } else {
                $uri = Uri::parse(\sprintf('%s://%s/%s', $scheme, $authority, \ltrim($path, '/')));
            }
            
            $request = new HttpRequest($uri, $method, [], null, '2.0');
            $request = $request->withRequestTarget($path);
            
            foreach ($headers as $header) {
                if (\substr($header[0], 0, 1) !== ':') {
                    $request = $request->withAddedHeader(...$header);
                }
            }
            
            $request = $request->withHeader('Host', $authority);
            
            if (\in_array('100-continue', $request->getHeaderTokenValues('Expect'), true)) {
                $request = $request->withBody(new ContinuationBody($this->entity, function (Context $context, $stream) {
                    yield from $this->sendHeaders($context, $this->hpack->encode([
                        [
                            ':status',
                            Http::CONTINUE
                        ]
                    ]));
                    
                    return $stream;
                }));
            } else {
                $request = $request->withBody(new StreamBody($this->entity));
            }
        } catch (\Throwable $e) {
            $this->close();
            
            throw $e;
        }
        
        return $request;
    }
    
    public function sendRequest(Context $context, HttpRequest $request): \Generator
    {
        try {
            $settings = $request->getAttribute(ClientSettings::class) ?? new ClientSettings();
            
            $uri = $request->getUri();
            $target = $request->getRequestTarget();
            
            if ($target === '*') {
                $path = '*';
            } else {
                $path = '/' . \ltrim($request->getRequestTarget(), '/');
            }
            
            $headers = [
                ':method' => (array) $request->getMethod(),
                ':scheme' => (array) $uri->getScheme(),
                ':authority' => (array) $uri->getAuthority(),
                ':path' => (array) $path
            ];
            
            if ($settings->isExpectContinue()) {
                $headers['expect'] = (array) '100-continue';
                $expect = true;
            } else {
                $expect = false;
            }
            
            $this->placeholder = new Placeholder($context);
            
            try {
                yield from $this->sendHeaders($context, $this->encodeHeaders($request, $headers, [
                    'host'
                ]));
                
                if ($expect) {
                    $headers = $this->hpack->decode(yield $context->keepBusy($this->placeholder->promise()));
                    $response = $this->unserializeResponse($headers, false);
                    
                    $this->placeholder = new Placeholder($context);
                    
                    if ($response->getStatusCode() != Http::CONTINUE) {
                        yield $request->getBody()->discard($context);
                        
                        return $response;
                    }
                }
                
                yield from $this->sendBody($context, $request);
                
                $headers = $this->hpack->decode(yield $context->keepBusy($this->placeholder->promise()));
            } finally {
                $this->placeholder = null;
            }
            
            $response = $this->unserializeResponse($headers);
        } catch (\Throwable $e) {
            $this->close($e);
            
            throw $e;
        }
        
        return $response;
    }
    
    protected function unserializeResponse(array $headers, bool $body = true)
    {
        $status = (int) $this->getFirstHeader(':status', $headers);
        
        $response = new HttpResponse($status, [], null, '2.0');
        $response = $response->withReason(Http::getReason($status));
        
        foreach ($headers as $header) {
            if (\substr($header[0], 0, 1) !== ':') {
                $response = $response->withAddedHeader(...$header);
            }
        }
        
        if ($body) {
            $response = $response->withBody(new StreamBody($this->entity));
        }
        
        return $response;
    }
    
    public function sendResponse(Context $context, HttpRequest $request, HttpResponse $response): \Generator
    {
        try {
            yield $request->getBody()->discard($context);
            
            $headers = [
                ':status' => [
                    (string) $response->getStatusCode()
                ]
            ];
            
            $head = ($request->getMethod() === Http::HEAD);
            $nobody = $head || Http::isResponseWithoutBody($response->getStatusCode());
            
            yield from $this->sendHeaders($context, $this->encodeHeaders($response, $headers, [
                'host'
            ]), $nobody);
            
            if (!$nobody) {
                yield from $this->sendBody($context, $response, $request->getBody());
            }
        } catch (\Throwable $e) {
            $this->close($e);
            
            throw $e;
        }
        
        $this->closed = true;
        
        $this->close();
    }
    
    protected function getFirstHeader(string $name, array $headers, string $default = ''): string
    {
        foreach ($headers as $header) {
            if ($header[0] === $name) {
                return $header[1];
            }
        }
        
        return $default;
    }
    
    protected function encodeHeaders(HttpMessage $message, array $headers, array $remove = [])
    {
        static $removeDefault = [
            'connection',
            'content-length',
            'keep-alive',
            'transfer-encoding',
            'te'
        ];
        
        foreach (\array_change_key_case($message->getHeaders(), \CASE_LOWER) as $k => $v) {
            if (!isset($headers[$k])) {
                $headers[$k] = $v;
            }
        }
        
        foreach ($removeDefault as $name) {
            unset($headers[$name]);
        }
        
        foreach ($remove as $name) {
            unset($headers[$name]);
        }
        
        $headerList = [];
        
        foreach ($headers as $k => $h) {
            foreach ($h as $v) {
                $headerList[] = [
                    $k,
                    $v
                ];
            }
        }
        
        return $this->hpack->encode($headerList);
    }
    
    protected function sendHeaders(Context $context, string $headers, bool $nobody = false): \Generator
    {
        $flags = Frame::END_HEADERS | ($nobody ? Frame::END_STREAM : Frame::NOFLAG);
        
        $chunkSize = 8192;
        
        if (\strlen($headers) > $chunkSize) {
            $parts = \str_split($headers, $chunkSize);
            $frames = [];
            
            $frames[] = new Frame(Frame::HEADERS, $this->id, $parts[0]);
            
            for ($size = \count($parts) - 2, $i = 1; $i < $size; $i++) {
                $frames[] = new Frame(Frame::CONTINUATION, $this->id, $parts[$i]);
            }
            
            $frames[] = new Frame(Frame::CONTINUATION, $this->id, $parts[\count($parts) - 1], $flags);
            
            yield $this->stream->writeFrames($context, $frames);
        } else {
            yield $this->stream->writeFrame($context, new Frame(Frame::HEADERS, $this->id, $headers, $flags));
        }
    }

    protected function sendBody(Context $context, HttpMessage $message, ?HttpBody $body = null): \Generator
    {
        $stream = yield $message->getBody()->getReadableStream($context);
        
        try {
            $chunkSize = 8192;
            $sent = 0;
            
            while (null !== ($chunk = yield $stream->read($context))) {
                $len = \strlen($chunk);
                
                if ($body) {
                    try {
                        yield $body->discard($context);
                    } finally {
                        $body = null;
                    }
                }
                
                while ($this->outputWindow < $len) {
                    $this->outputDefer = new Placeholder($context);
                    
                    try {
                        yield $this->outputDefer->promise();
                    } finally {
                        $this->outputDefer = null;
                    }
                }
                
                while ($this->connection->getOutputWindow() < $len) {
                    yield $this->connection->waitForWindowUpdate();
                }
                
                $written = yield $this->stream->writeFrame($context, new Frame(Frame::DATA, $this->id, $chunk));
                
                $sent += $written;
                $this->outputWindow -= $written;
            }
            
            yield $this->stream->writeFrame($context, new Frame(Frame::DATA, $this->id, '', Frame::END_STREAM));
        } finally {
            $stream->close();
            
            if ($body) {
                yield $body->discard($context);
            }
        }
        
        return $sent;
    }

    public function processHeadersFrame(Frame $frame): void
    {
        $data = $frame->getPayload();
        
        if ($frame->flags & Frame::PRIORITY_FLAG) {
            $data = \substr($data, 5);
        }
        
        $this->headers .= $data;
        
        if ($frame->flags & Frame::END_HEADERS) {
            if ($frame->flags & Frame::END_STREAM) {
                $this->entity->finish();
                
                if (!$this->connection->isServer()) {
                    $this->closed = true;
                }
            }
            
            try {
                $this->placeholder->resolve($this->headers);
            } finally {
                $this->headers = '';
            }
        }
    }

    public function processContinuationFrame(Frame $frame): void
    {
        $this->headers .= $frame->data;
        
        if ($frame->flags & Frame::END_HEADERS) {
            try {
                $this->placeholder->resolve($this->headers);
            } finally {
                $this->headers = '';
            }
        }
    }

    public function processDataFrame(Frame $frame): void
    {
        $this->entity->appendData($frame->getPayload());
        
        if ($frame->flags & Frame::END_STREAM) {
            $this->entity->finish();
            
            if (!$this->connection->isServer()) {
                $this->closed = true;
            }
        }
    }

    public function processWindowUpdateFrame(Frame $frame): void
    {
        $increment = unpack('N', "\x7F\xFF\xFF\xFF" & $frame->data)[1];
        
        if ($increment < 1) {
            throw new ConnectionException('WINDOW_UPDATE increment must be positive and bigger than 0', Frame::PROTOCOL_ERROR);
        }
        
        $this->outputWindow += $increment;
        
        if ($this->outputDefer) {
            $this->outputDefer->resolve($increment);
        }
    }
}
