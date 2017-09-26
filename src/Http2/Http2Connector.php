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
use KoolKode\Async\Placeholder;
use KoolKode\Async\Promise;
use KoolKode\Async\Success;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Stream\DuplexStream;

class Http2Connector implements HttpConnector
{
    protected $hpack;
    
    protected $connecting = [];
    
    protected $connections = [];
    
    public function __construct(?HPackContext $hpack = null)
    {
        $this->hpack = $hpack ?? new HPackClientContext();
    }
    
    public function getPriority(): int
    {
        return 20;
    }

    public function isRequestSupported(HttpRequest $request): bool
    {
        if ($request->getUri()->getScheme() != 'https') {
            return false;
        }
        
        return (float) $request->getProtocolVersion() >= 2;
    }

    public function isConnected(Context $context, string $key): Promise
    {
        if (isset($this->connecting[$key])) {
            $placeholder = new Placeholder($context);
            $placeholder->resolve($this->connecting[$key]);
            
            return $placeholder->promise();
        }
        
        return new Success($context, isset($this->connections[$key]));
    }

    public function getProtocols(): array
    {
        return [
            'h2'
        ];
    }
    
    public function isSupported(string $protocol): bool
    {
        return $protocol == 'h2';
    }

    public function send(Context $context, HttpRequest $request, ?DuplexStream $stream = null): Promise
    {
        return $context->task($this->processRequest($context, $request, $stream));
    }

    protected function processRequest(Context $context, HttpRequest $request, ?DuplexStream $stream = null): \Generator
    {
        $uri = $request->getUri();
        $key = $uri->getScheme() . '://' . $uri->getHostWithPort(true);
        
        if ($stream) {
            $this->connecting[$key] = $placeholder = new Placeholder($context);
            
            try {
                try {
                    $conn = yield from $this->connectClient($context, $stream);
                } finally {
                    unset($this->connecting[$key]);
                }
            } catch (\Throwable $e) {
                $placeholder->resolve(false);
                
                throw $e;
            }
            
            $placeholder->resolve(true);
        } else {
            $conn = $this->connections[$key];
        }
        
        return yield $conn->send($context, $request);
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
    
    protected function connectClient(Context $context, DuplexStream $stream): \Generator
    {
        try {
            yield $stream->write($context, Connection::PREFACE);
            
            $stream = new FramedStream($stream, $stream);
            
            $settings = '';
            
            foreach ($this->localSettings as $k => $v) {
                $settings .= \pack('nN', $k, $v);
            }
            
            yield $stream->writeFrame($context, new Frame(Frame::SETTINGS, 0, $settings));
            yield $stream->writeFrame($context, new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', 0x0FFFFFFF)));
            
            $frame = yield $stream->readFrame($context);
            
            if ($frame->stream !== 0 || $frame->type !== Frame::SETTINGS) {
                throw new ConnectionException('Failed to establish HTTP/2 connection');
            }
            
            // TODO: Implement and apply settings.
        } catch (\Throwable $e) {
            $stream->close();
            
            throw $e;
        }
        
        return new Connection($context, Connection::CLIENT, $stream, new HPack($this->hpack));
    }
}
