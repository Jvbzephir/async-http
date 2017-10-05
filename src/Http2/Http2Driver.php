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
use KoolKode\Async\Promise;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpDriver;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\DuplexStream;

class Http2Driver implements HttpDriver
{
    protected $hpack;
    
    public function __construct(?HPackContext $hpack = null)
    {
        $this->hpack = $hpack ?? new HPackServerContext();
    }
    
    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 20;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getProtocols(): array
    {
        return [
            'h2'
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function isSupported(string $protocol): bool
    {
        return $protocol == 'h2';
    }

    /**
     * {@inheritdoc}
     */
    public function listen(Context $context, DuplexStream $stream, callable $action): Promise
    {
        return $context->task(function (Context $context) use ($stream, $action) {
            $conn = yield from $this->connectServer($context, $stream);
            
            try {
                while (null !== ($stream = yield $conn->receive($context))) {
                    Context::rethrow($context->task($this->processRequest($context, $stream, $action)));
                }
            } finally {
                $conn->close();
            }
        });
    }
    
    protected function processRequest(Context $context, Stream $stream, callable $action): \Generator
    {
        try {
            $request = yield from $stream->receiveRequest($context);
            
            $response = $action($context, $request, function (Context $context, $response) {
                if ($response instanceof \Generator) {
                    $response = yield from $response;
                }
                
                if (!$response instanceof HttpResponse) {
                    $response = new HttpResponse(Http::INTERNAL_SERVER_ERROR);
                }
                
                return $response;
            });
            
            if ($response instanceof \Generator) {
                $response = yield from $response;
            }
            
            yield from $stream->sendResponse($context, $request, $response);
        } finally {
            $stream->close();
        }
    }
    
    protected $localSettings = [
        Connection::SETTING_ENABLE_PUSH => 0,
        Connection::SETTING_MAX_CONCURRENT_STREAMS => 256,
        Connection::SETTING_INITIAL_WINDOW_SIZE => 0xFFFF,
        Connection::SETTING_MAX_FRAME_SIZE => 16384
    ];
    
    protected $remoteSettings = [
        Connection::SETTING_HEADER_TABLE_SIZE => 4096,
        Connection::SETTING_ENABLE_PUSH => 1,
        Connection::SETTING_MAX_CONCURRENT_STREAMS => 100,
        Connection::SETTING_INITIAL_WINDOW_SIZE => 0xFFFF,
        Connection::SETTING_MAX_FRAME_SIZE => 16384,
        Connection::SETTING_MAX_HEADER_LIST_SIZE => 16777216
    ];

    protected function connectServer(Context $context, DuplexStream $stream): \Generator
    {
        try {
            $preface = yield $stream->readBuffer($context, \strlen(Connection::PREFACE));
            
            if ($preface != Connection::PREFACE) {
                throw new ConnectionException('Failed to read HTTP/2 connection preface');
            }
            
            $stream = new FramedStream($stream, $stream);
            $frame = yield $stream->readFrame($context);
            
            if ($frame->stream !== 0 || $frame->type !== Frame::SETTINGS) {
                throw new ConnectionException('Failed to establish HTTP/2 connection');
            }
            
            $settings = '';
            
            foreach ($this->localSettings as $k => $v) {
                $settings .= \pack('nN', $k, $v);
            }
            
            yield $stream->writeFrame($context, new Frame(Frame::SETTINGS, 0, $settings));
            yield $stream->writeFrame($context, new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', 0x0FFFFFFF)));
            
            // TODO: Implement and apply settings.
        } catch (\Throwable $e) {
            $stream->close();
            
            throw $e;
        }
        
        return new Connection($context, Connection::SERVER, $stream, new HPack($this->hpack));
    }
}
