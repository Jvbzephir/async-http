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
use KoolKode\Async\Deferred;
use KoolKode\Async\Placeholder;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpMessage;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Stream\ReadableStream;

class Stream
{
    protected $id;
    
    protected $connection;
    
    protected $hpack;
    
    protected $stream;
    
    protected $placeholder;
    
    protected $headers = '';
    
    protected $entity;
    
    protected $inputWindow;
    
    public function __construct(int $id, Connection $connection, FramedStream $stream, HPack $hpack, int $window = Connection::INITIAL_WINDOW_SIZE)
    {
        $this->id = $id;
        $this->connection = $connection;
        $this->stream = $stream;
        $this->hpack = $hpack;
        $this->inputWindow = $window;
        
        $this->entity = new EntityStream($this->connection, $this->id);
    }
    
    public function close(): void
    {
        $this->connection->closeStream($this->id);
    }
    
    public function sendRequest(Context $context, HttpRequest $request): \Generator
    {
        try {
            $uri = $request->getUri();
            $target = $request->getRequestTarget();
            
            if ($target === '*') {
                $path = '*';
            } else {
                $path = '/' . \ltrim($request->getRequestTarget(), '/');
            }
            
            $this->placeholder = new Placeholder($context);
            
            try {
                yield from $this->sendHeaders($context, $this->encodeHeaders($request, [
                    ':method' => (array) $request->getMethod(),
                    ':scheme' => (array) $uri->getScheme(),
                    ':authority' => (array) $uri->getAuthority(),
                    ':path' => (array) $path
                ], [
                    'host'
                ]));
                
                $sent = yield from $this->sendBody($context, yield $request->getBody()->getReadableStream($context));
                
                $headers = $this->hpack->decode(yield from $this->connection->busyWait($context, $this->placeholder->promise()));
            } finally {
                $this->placeholder = null;
            }
            
            $status = (int) $this->getFirstHeader(':status', $headers);
            
            $response = new HttpResponse($status);
            $response = $response->withReason(Http::getReason($status));
            $response = $response->withProtocolVersion('2.0');
            $response = $response->withBody(new StreamBody($this->entity));
            
            foreach ($headers as $header) {
                if (\substr($header[0], 0, 1) !== ':') {
                    $response = $response->withAddedHeader(...$header);
                }
            }
        } catch (\Throwable $e) {
            $this->close();
            
            throw $e;
        }
        
        return $response;
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
    
    protected function sendHeaders(Context $context, string $headers, bool $end = false): \Generator
    {
        $flags = Frame::END_HEADERS | ($end ? Frame::END_STREAM : Frame::NOFLAG);
        
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

    protected function sendBody(Context $context, ReadableStream $body): \Generator
    {
        $chunkSize = 8192;
        
        $done = false;
        $sent = 0;
        
        try {
            while (null !== ($chunk = yield $body->read($context))) {
                $len = \strlen($chunk);
                
                while (true) {
                    if ($this->outputWindow < $len) {
                        try {
                            yield $this->outputDefer = new Deferred();
                        } finally {
                            $this->outputDefer = null;
                        }
                        
                        continue;
                    }
                    
                    if ($this->conn->getOutputWindow() < $len) {
                        yield $this->conn->awaitWindowUpdate();
                        
                        continue;
                    }
                    
                    break;
                }
                
                if ($len < $chunkSize) {
                    $done = true;
                    $frame = new Frame(Frame::DATA, $this->id, $chunk, Frame::END_STREAM);
                } else {
                    $frame = new Frame(Frame::DATA, $this->id, $chunk);
                }
                
                $written = yield $this->stream->writeFrame($context, $frame);
                
                $sent += $written;
                $this->outputWindow -= $written;
            }
            
            if (!$done) {
                yield $this->stream->writeFrame($context, new Frame(Frame::DATA, $this->id, '', Frame::END_STREAM));
            }
        } finally {
            $body->close();
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
        }
    }
}
