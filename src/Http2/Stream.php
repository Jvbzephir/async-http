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

use KoolKode\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Deferred;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Http\HttpMessage;

class Stream
{
    protected $id;
    
    protected $conn;
    
    protected $hpack;
    
    protected $defer;
    
    public function __construct(int $id, Connection $conn)
    {
        $this->id = $id;
        $this->conn = $conn;
        $this->hpack = $conn->getHPack();
    }
    
    public function processFrame(Frame $frame)
    {
        fwrite(STDERR, sprintf("\n[%s] << %s", $this->id, $frame));
        
        switch ($frame->type) {
            case Frame::HEADERS:
                if ($frame->flags & Frame::PADDED) {
                    $data = \substr($frame->data, 1, -1 * \ord($frame->data[0]));
                } else {
                    $data = $frame->data;
                }
                
                if ($frame->flags & Frame::PRIORITY_FLAG) {
                    $data = \substr($data, 5);
                }
                
                if ($frame->flags & Frame::END_HEADERS) {
                    fwrite(STDERR, "\n" . json_encode($this->hpack->decode($frame->data)));
                }
                break;
            case Frame::DATA:
                fwrite(STDERR, "\n" . $frame->data);
                break;
        }
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
            
            $defer = new Deferred();
            
            return yield $defer;
        });
    }
    
    protected function sendHeaders(HttpMessage $message, array $headers): \Generator
    {
        static $remove = [
            'connection',
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
        $flags = Frame::END_HEADERS;
        
        // Header size: 9 byte general header (optional: +4 bytes for stream dependency, +1 byte for weight).
        $chunkSize = 4096 - 9;
        
        if (\strlen($headers) > $chunkSize) {
            $parts = \str_split($headers, $chunkSize);
            $frames = [
                new Frame(Frame::HEADERS, $parts[0])
            ];
            
            for ($size = \count($parts) - 2, $i = 1; $i < $size; $i++) {
                $frames[] = new Frame(Frame::CONTINUATION, $parts[$i]);
            }
            
            $frames[] = new Frame(Frame::CONTINUATION, $parts[\count($parts) - 1], $flags);
            
            // Send all frames in one batch to ensure no concurrent writes to the socket take place.
            yield $this->conn->writeStreamFrames($this->id, $frames);
        } else {
            yield $this->conn->writeStreamFrame($this->id, new Frame(Frame::HEADERS, $headers, $flags));
        }
    }
    
    protected function sendBody(ReadableStream $body): \Generator
    {
        $channel = $body->channel(4087);
        
        try {
            while (null !== ($chunk = yield $channel->receive())) {
                yield $this->conn->writeStreamFrame($this->id, new Frame(Frame::DATA, $chunk));
            }
            
            yield $this->conn->writeStreamFrame($this->id, new Frame(Frame::DATA, '', Frame::END_STREAM));
        } finally {
            $body->close();
        }
    }
}
