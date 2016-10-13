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

use Interop\Async\Loop;
use KoolKode\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Deferred;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpMessage;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StreamBody;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Util\Channel;
use Psr\Log\LoggerInterface;

/**
 * Implements an HTTP/2 stream that is multiplexed over a connection.
 * 
 * @author Martin Schröder
 */
class Stream
{
    protected $id;
    
    protected $conn;
    
    protected $hpack;
    
    protected $defer;
    
    protected $headers = '';
    
    protected $eof = false;
    
    protected $channel;
    
    protected $inputWindow = Connection::INITIAL_WINDOW_SIZE;

    protected $outputWindow;

    protected $outputDefer;
    
    protected $logger;

    public function __construct(int $id, Connection $conn, int $outputWindow = Connection::INITIAL_WINDOW_SIZE, LoggerInterface $logger = null)
    {
        $this->id = $id;
        $this->conn = $conn;
        $this->outputWindow = $outputWindow;
        $this->logger = $logger;
        
        $this->hpack = $conn->getHPack();
        $this->defer = new Deferred();
    }
    
    public function getId(): int
    {
        return $this->id;
    }
    
    public function getDefer(): Awaitable
    {
        return $this->defer;
    }
    
    public function close(\Throwable $e = null)
    {
        if ($e === null) {
            $e = new StreamClosedException('HTTP/2 stream has been closed');
        }
        
        $this->defer->fail($e);
        
        if ($this->outputDefer) {
            $this->outputDefer->fail($e);
        }
        
        if ($this->channel !== null) {
            $this->channel->close($e);
        }
    }
    
    public function processFrame(Frame $frame)
    {
        if ($frame->type === Frame::RST_STREAM) {
            return $this->conn->closeStream($this->id);
        }
        
        switch ($frame->type) {
            case Frame::GOAWAY:
                throw new ConnectionException('GOAWAY must be sent to a connection', Frame::PROTOCOL_ERROR);
            case Frame::PING:
                throw new ConnectionException('PING frame must not be sent to a stream', Frame::PROTOCOL_ERROR);
            case Frame::SETTINGS:
                throw new ConnectionException('SETTINGS frames must not be sent to an open stream', Frame::PROTOCOL_ERROR);
        }
    }

    public function processHeadersFrame(Frame $frame)
    {
        if ($frame->flags & Frame::PADDED) {
            $data = \substr($frame->data, 1, -1 * \ord($frame->data[0]));
        } else {
            $data = $frame->data;
        }
        
        if ($frame->flags & Frame::PRIORITY_FLAG) {
            $data = \substr($data, 5);
        }
        
        $this->headers .= $data;
        
        if ($frame->flags & Frame::END_HEADERS) {
            if ($frame->flags & Frame::END_STREAM) {
                $this->eof = true;
            }
            
            $this->resolveMessage();
        }
    }
    
    public function processContinuationFrame(Frame $frame)
    {
        $this->headers .= $frame->data;
        
        if ($frame->flags & Frame::END_HEADERS) {
            $this->resolveMessage();
        }
    }

    public function processDataFrame(Frame $frame): \Generator
    {
        if ($this->channel === null) {
            $this->channel = new Channel(128);
        } elseif ($this->channel->isClosed()) {
            return;
        }
        
        if ($frame->flags & Frame::PADDED) {
            yield $this->channel->send(\substr($frame->data, 1, -1 * \ord($frame->data[0])));
        } else {
            yield $this->channel->send($frame->data);
        }
        
        if ($frame->flags & Frame::END_STREAM) {
            $this->channel->close();
        }
    }
    
    public function processWindowUpdateFrame(Frame $frame)
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

    protected function resolveMessage()
    {
        try {
            $headers = $this->hpack->decode($this->headers);
        } finally {
            $this->headers = '';
        }
        
        if ($this->conn->isClient()) {
            $message = $this->resolveResponse($headers);
        } else {
            $message = $this->resolveRequest($headers);
        }
        
        if (!$this->eof) {
            if ($this->channel === null) {
                $this->channel = new Channel(1000);
            }
            
            $message = $message->withBody(new StreamBody(new EntityStream($this->channel, $this->conn, $this->id, $this->inputWindow)));
        }
        
        Loop::defer(function () use ($message) {
            $this->defer->resolve([
                $this,
                $message
            ]);
        });
    }

    protected function resolveRequest(array $headers): HttpRequest
    {
        $scheme = $this->getFirstHeader(':scheme', $headers, 'http');
        $authority = $this->getFirstHeader(':authority', $headers);
        $method = $this->getFirstHeader(':method', $headers, Http::GET);
        $path = $this->getFirstHeader(':path', $headers, '/');
        
        $uri = Uri::parse(\sprintf('%s://%s/%s', $scheme, $authority, \ltrim($path, '/')));
        
        $request = new HttpRequest($uri, $method);
        $request = $request->withProtocolVersion('2.0');
        
        foreach ($headers as $header) {
            if (\substr($header[0], 0, 1) !== ':') {
                $request = $request->withAddedHeader(...$header);
            }
        }
        
        return $request;
    }

    protected function resolveResponse(array $headers): HttpResponse
    {
        $response = new HttpResponse((int) $this->getFirstHeader(':status', $headers));
        $response = $response->withProtocolVersion('2.0');
        
        foreach ($headers as $header) {
            if (\substr($header[0], 0, 1) !== ':') {
                $response = $response->withAddedHeader(...$header);
            }
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

    public function sendRequest(HttpRequest $request): Awaitable
    {
        return new Coroutine(function () use ($request) {
            $uri = $request->getUri();
            $path = '/' . \ltrim($request->getRequestTarget(), '/');
            
            $headers = [
                ':method' => [
                    $request->getMethod()
                ],
                ':scheme' => [
                    $uri->getScheme()
                ],
                ':authority' => [
                    $uri->getAuthority()
                ],
                ':path' => [
                    $path
                ]
            ];
            
            $bodyStream = yield $request->getBody()->getReadableStream();
            
            yield from $this->sendHeaders($request, $headers);
            yield from $this->sendBody($bodyStream);
            
            return (yield $this->defer)[1];
        });
    }
    
    public function sendResponse(HttpRequest $request, HttpResponse $response): Awaitable
    {
        return new Coroutine(function () use ($request, $response) {
            try {
                $headers = [
                    ':status' => [
                        (string) $response->getStatusCode()
                    ]
                ];
                
                $bodyStream = yield $response->getBody()->getReadableStream();
                
                yield from $this->sendHeaders($response, $headers);
                yield from $this->sendBody($bodyStream);
            } finally {
                $this->conn->closeStream($this->id);
            }
        });
    }
    
    protected function sendHeaders(HttpMessage $message, array $headers): \Generator
    {
        static $remove = [
            'connection',
            'content-encoding',
            'content-length',
            'host',
            'keep-alive',
            'host',
            'transfer-encoding',
            'upgrade',
            'te'
        ];
        
        foreach (\array_change_key_case($message->getHeaders(), \CASE_LOWER) as $k => $v) {
            if (!isset($headers[$k])) {
                $headers[$k] = $v;
            }
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
        
        $headers = $this->hpack->encode($headerList);
        
        $chunkSize = \min(4087, $this->conn->getRemoteSetting(Connection::SETTING_MAX_FRAME_SIZE));
        
        if (\strlen($headers) > $chunkSize) {
            $parts = \str_split($headers, $chunkSize);
            $frames = [
                new Frame(Frame::HEADERS, $parts[0])
            ];
            
            for ($size = \count($parts) - 2, $i = 1; $i < $size; $i++) {
                $frames[] = new Frame(Frame::CONTINUATION, $parts[$i]);
            }
            
            $frames[] = new Frame(Frame::CONTINUATION, $parts[\count($parts) - 1], Frame::END_HEADERS);
            
            yield $this->conn->writeStreamFrames($this->id, $frames);
        } else {
            yield $this->conn->writeStreamFrame($this->id, new Frame(Frame::HEADERS, $headers, Frame::END_HEADERS));
        }
    }
    
    protected function sendBody(ReadableStream $body): \Generator
    {
        $chunkSize = \min(4087, $this->conn->getRemoteSetting(Connection::SETTING_MAX_FRAME_SIZE));
        $channel = $body->channel($chunkSize);
        
        try {
            while (null !== ($chunk = yield $channel->receive())) {
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
                
                $frame = new Frame(Frame::DATA, $chunk, ($len < $chunkSize) ? Frame::END_STREAM : Frame::NOFLAG);
                
                $this->outputWindow -= yield $this->conn->writeStreamFrame($this->id, $frame);
            }
            
            if (!isset($len)) {
                yield $this->conn->writeStreamFrame($this->id, new Frame(Frame::DATA, '', Frame::END_STREAM));
            }
        } finally {
            $body->close();
        }
    }
}
