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
use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Transform;
use KoolKode\Async\Util\Channel;
use KoolKode\Async\Util\Executor;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use KoolKode\Async\Stream\StreamClosedException;

/**
 * Provides access to an HTTP/2 connection that is being used to multiplex frames across streams.
 * 
 * @author Martin Schröder
 */
class Connection implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    /**
     * Connection preface that must be sent by the client.
     *
     * @var string
     */
    const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    
    /**
     * Connection preface HTTP body that is sent by the client.
     * 
     * @var string
     */
    const PREFACE_BODY = "SM\r\n\r\n";
    
    /**
     * Initial window is 64 KB.
     *
     * @var int
     */
    const INITIAL_WINDOW_SIZE = 0xFFFF;
    
    const SETTING_HEADER_TABLE_SIZE = 0x01;
    
    const SETTING_ENABLE_PUSH = 0x02;
    
    const SETTING_MAX_CONCURRENT_STREAMS = 0x03;
    
    const SETTING_INITIAL_WINDOW_SIZE = 0x04;
    
    const SETTING_MAX_FRAME_SIZE = 0x05;
    
    const SETTING_MAX_HEADER_LIST_SIZE = 0x06;
    
    protected $socket;
    
    protected $hpack;
    
    protected $writer;
    
    protected $processor;
    
    protected $client;
    
    protected $nextStreamId;
    
    protected $streams = [];
    
    protected $outputWindow = self::INITIAL_WINDOW_SIZE;
    
    protected $outputDefer;
    
    protected $localSettings = [
        self::SETTING_ENABLE_PUSH => 0,
        self::SETTING_MAX_CONCURRENT_STREAMS => 256,
        self::SETTING_INITIAL_WINDOW_SIZE => 0xFFFF,
        self::SETTING_MAX_FRAME_SIZE => 16384
    ];
    
    protected $remoteSettings = [
        self::SETTING_HEADER_TABLE_SIZE => 4096,
        self::SETTING_ENABLE_PUSH => 1,
        self::SETTING_MAX_CONCURRENT_STREAMS => 100,
        self::SETTING_INITIAL_WINDOW_SIZE => 0xFFFF,
        self::SETTING_MAX_FRAME_SIZE => 16384,
        self::SETTING_MAX_HEADER_LIST_SIZE => 16777216
    ];
    
    protected $pings = [];
    
    protected $incoming;
    
    /**
     * @var string
     */
    protected $remoteAddress;

    public function __construct(SocketStream $socket, HPack $hpack)
    {
        $this->socket = $socket;
        $this->hpack = $hpack;
        
        $this->writer = new Executor();
        $this->incoming = new Channel();
        
        $parts = \explode(':', $socket->getRemoteAddress());
        \array_pop($parts);
        
        $this->remoteAddress = \implode(':', $parts);
        
        if ($this->remoteAddress === '') {
            $this->remoteAddress = '127.0.0.1';
        }
    }
    
    public function isAlive(): bool
    {
        return $this->socket->isAlive();
    }

    public function isClient(): bool
    {
        return $this->client;
    }
    
    public function shutdown()
    {
        if ($this->processor !== null) {
            $this->processor->cancel('Connection shutdown', new StreamClosedException('Connection shutdown'));
        }
    }
    
    public function getHPack(): HPack
    {
        return $this->hpack;
    }

    public function getRemoteSetting(int $setting): int
    {
        if (isset($this->remoteSettings[$setting])) {
            return $this->remoteSettings[$setting];
        }
        
        throw new \OutOfBoundsException(\sprintf('Remote setting not found: "%s"', $setting));
    }
    
    public function performClientHandshake(): Awaitable
    {
        return new Coroutine(function () {
            if ($this->client !== null) {
                throw new \RuntimeException('HTTP/2 handshake has already been performed');
            }
            
            $this->client = true;
            
            yield $this->socket->write(self::PREFACE);
            
            $settings = '';
            
            foreach ($this->localSettings as $k => $v) {
                $settings .= \pack('nN', $k, $v);
            }
            
            yield $this->writeFrame(new Frame(Frame::SETTINGS, $settings));
            yield $this->writeFrame(new Frame(Frame::WINDOW_UPDATE, \pack('N', 0x0FFFFFFF)));
            
            $stream = 0;
            $frame = yield from $this->readNextFrame($stream);
            
            if ($stream !== 0 || $frame->type !== Frame::SETTINGS) {
                throw new ConnectionException('Failed to establish HTTP/2 connection');
            }
            
            $frames = new \SplQueue();
            $frames->enqueue([
                $stream,
                $frame
            ]);
            
            $this->nextStreamId = 1;
            $this->processor = new Coroutine($this->processIncomingFrames($frames), true);
            
            if ($this->logger) {
                $this->logger->debug('Performed HTTP/2 client handshake with {peer}', [
                    'peer' => $this->socket->getRemoteAddress()
                ]);
            }
        });
    }

    public function performServerHandshake(Frame $frame = null, bool $skipPreface = false): Awaitable
    {
        return new Coroutine(function () use ($frame, $skipPreface) {
            if ($this->client !== null) {
                throw new \RuntimeException('HTTP/2 handshake has already been performed');
            }
            
            $this->client = false;
            
            if (!$skipPreface) {
                $preface = yield $this->socket->readBuffer(\strlen(self::PREFACE), true);
                
                if ($preface !== self::PREFACE) {
                    throw new ConnectionException('Did not receive valid HTTP/2 connection preface from client');
                }
            }
            
            $stream = 0;
            
            if ($frame === null) {
                $frame = yield from $this->readNextFrame($stream);
            }
            
            if ($stream !== 0 || $frame->type !== Frame::SETTINGS) {
                throw new ConnectionException('Failed to establish HTTP/2 connection');
            }
            
            $this->processSettingsFrame($frame);
            
            $settings = '';
            
            foreach ($this->localSettings as $k => $v) {
                $settings .= \pack('nN', $k, $v);
            }
            
            yield $this->writeFrame(new Frame(Frame::SETTINGS, $settings));
            yield $this->writeFrame(new Frame(Frame::WINDOW_UPDATE, \pack('N', 0x0FFFFFFF)));
            
            $this->nextStreamId = 2;
            $this->processor = new Coroutine($this->processIncomingFrames(), true);
            
            if ($this->logger) {
                $this->logger->debug('Performed HTTP/2 server handshake with {peer}', [
                    'peer' => $this->socket->getRemoteAddress()
                ]);
            }
        });
    }
    
    public function openStream(): Stream
    {
        $stream = new Stream($this->nextStreamId, $this, $this->remoteSettings[self::SETTING_INITIAL_WINDOW_SIZE]);
        
        if ($this->logger) {
            $stream->setLogger($this->logger);
        }
        
        $this->nextStreamId += 2;
        
        return $this->streams[$stream->getId()] = $stream;
    }
    
    public function nextRequest(HttpDriverContext $context): Awaitable
    {
        return new Transform($this->incoming->receive(), function (Stream $stream, HttpRequest $request) use ($context) {
            $addresses = [
                $this->remoteAddress
            ];
            
            $proxy = $context->getProxySettings();
            
            if ($proxy->isTrustedProxy($this->remoteAddress)) {
                $scheme = $request->getUri()->getScheme();
                $proxied = $proxy->getScheme($request) ?? $scheme;
                
                if ($proxied != $scheme) {
                    $request = $request->withUri($request->getUri()->withScheme($proxied));
                }
                
                if (null !== ($host = $proxy->getHost($request))) {
                    $request = $request->withUri($request->getUri()->withPort(null)->withHost($host));
                }
                
                $addresses = \array_merge($proxy->getAddresses($request), $addresses);
            }
            
            $request = $request->withAttribute(HttpDriverContext::class, $context);
            $request = $request->withAddress(...$addresses);
            
            return [
                $stream,
                $request
            ];
        }, true);
    }
    
    protected function openServerStream(int $streamId): Stream
    {
        if ($streamId < 1 || 0 === ($streamId % 2)) {
            throw new ConnectionException('Streams opened by client must use an uneven stream identfier', Frame::PROTOCOL_ERROR);
        }
        
        if (isset($this->streams[$streamId])) {
            throw new ConnectionException(\sprintf('Cannot open stream %u because it is still open', $streamId), Frame::PROTOCOL_ERROR);
        }
        
        $stream = new Stream($streamId, $this, $this->remoteSettings[self::SETTING_INITIAL_WINDOW_SIZE]);
        
        if ($this->logger) {
            $stream->setLogger($this->logger);
        }
        
        $stream->getDefer()->when(function (\Throwable $e = null, $val = null) {
            $this->incoming->send($e ?? $val);
        });
        
        return $this->streams[$streamId] = $stream;
    }
    
    public function closeStream(int $streamId)
    {
        if (isset($this->streams[$streamId])) {
            $this->streams[$streamId]->close();
            
            unset($this->streams[$streamId]);
        }
    }
    
    public function ping(): Awaitable
    {
        $payload = \random_bytes(8);
        
        $defer = new Deferred(function () use ($payload) {
            unset($this->pings[$payload]);
        });
        
        $this->writeFrame(new Frame(Frame::PING, $payload), 1000)->when(function (\Throwable $e = null) use ($defer, $payload) {
            if ($e) {
                $defer->fail($e);
            } else {
                $this->pings[$payload] = $defer;
            }
        });
        
        return $defer;
    }
    
    protected function readNextFrame(int & $stream): \Generator
    {
        $header = yield $this->socket->readBuffer(9, true);
        
        $length = \unpack('N', "\x00" . $header)[1];
        $type = \ord($header[3]);
        $stream = \unpack('N', "\x7F\xFF\xFF\xFF" & \substr($header, 5, 4))[1];
        
        if ($length > 0) {
            $frame = new Frame($type, yield $this->socket->readBuffer($length, true), \ord($header[4]));
        } else {
            $frame = new Frame($type, '', \ord($header[4]));
        }
        
        return $frame;
    }

    protected function processIncomingFrames(\SplQueue $frames = null): \Generator
    {
        $stream = 0;
        
        try {
            while (true) {
                if ($frames === null) {
                    $frame = yield from $this->readNextFrame($stream);
                } else {
                    list ($stream, $frame) = $frames->dequeue();
                    
                    if ($frames->isEmpty()) {
                        $frames = null;
                    }
                }
                
                if ($stream === 0) {
                    switch ($frame->type) {
                        case Frame::PING:
                            yield from $this->processPingFrame($frame);
                            break;
                        case Frame::SETTINGS:
                            yield from $this->processSettingsFrame($frame);
                            break;
                        case Frame::WINDOW_UPDATE:
                            $this->processWindowUpdateFrame($frame);
                            break;
                        default:
                            if ($this->processFrame($frame)) {
                                break 2;
                            }
                    }
                } else {
                    yield from $this->processStreamFrame($stream, $frame);
                }
            }
        } finally {
            try {
                if ($this->outputDefer !== null) {
                    $this->outputDefer->fail(new \RuntimeException('HTTP/2 connection closed'));
                }
                
                try {
                    foreach ($this->pings as $ping) {
                        $ping->fail(new \RuntimeException('HTTP/2 connection closed'));
                    }
                } finally {
                    $this->pings = [];
                }
                
                $this->incoming->close(new \RuntimeException('HTTP/2 connection closed'));
                
                try {
                    foreach ($this->streams as $stream) {
                        $stream->close();
                    }
                } finally {
                    $this->streams = [];
                }
                
                yield $this->writeFrame(new Frame(Frame::GOAWAY, ''));
            } finally {
                $this->socket->close();
            }
        }
    }

    protected function processFrame(Frame $frame)
    {
        switch ($frame->type) {
            case Frame::CONTINUATION:
                throw new ConnectionException('CONTINUATION frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::DATA:
                throw new ConnectionException('DATA frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::GOAWAY:
                return true;
            case Frame::HEADERS:
                throw new ConnectionException('HEADERS frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::PRIORITY:
                throw new ConnectionException('PRIORITY frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::PUSH_PROMISE:
                throw new ConnectionException('PUSH_PROMISE is not supported by this server', Frame::PROTOCOL_ERROR);
            case Frame::RST_STREAM:
                throw new ConnectionException('RST_STREAM frame must not be sent to connection', Frame::PROTOCOL_ERROR);
        }
    }

    protected function processPingFrame(Frame $frame): \Generator
    {
        if (\strlen($frame->data) !== 8) {
            throw new ConnectionException('PING frame payload must consist of 8 octets', Frame::FRAME_SIZE_ERROR);
        }
        
        if ($frame->flags & Frame::ACK) {
            if (isset($this->pings[$frame->data])) {
                try {
                    $this->pings[$frame->data]->resolve(true);
                } finally {
                    unset($this->pings[$frame->data]);
                }
            }
        } else {
            yield $this->writeFrame(new Frame(Frame::PING, $frame->data, Frame::ACK), 1000);
        }
    }

    protected function processSettingsFrame(Frame $frame): \Generator
    {
        if ($frame->flags & Frame::ACK) {
            if ($frame->data !== '') {
                throw new ConnectionException('ACK SETTINGS frame must not have a length of more than 0 bytes', Frame::FRAME_SIZE_ERROR);
            }
            
            return;
        }
        
        if (\strlen($frame->data) % 6) {
            throw new ConnectionException('SETTINGS frame payload length must be a multiple of 6 ', Frame::FRAME_SIZE_ERROR);
        }
        
        foreach (\str_split($frame->data, 6) as $setting) {
            list ($key, $value) = \array_values(\unpack('nk/Nv', $setting));
            
            switch ($key) {
                case self::SETTING_ENABLE_PUSH:
                    if ($value !== 0 && $value !== 1) {
                        throw new ConnectionException('Invalid enable push setting received', Frame::PROTOCOL_ERROR);
                    }
                    
                    $this->remoteSettings[self::SETTING_ENABLE_PUSH] = $value ? 1 : 0;
                    break;
                case self::SETTING_HEADER_TABLE_SIZE:
                    if ($value < 4096) {
                        throw new ConnectionException('Header table size must be at least 4096 bytes', Frame::COMPRESSION_ERROR);
                    }
                    
                    $this->remoteSettings[self::SETTING_HEADER_TABLE_SIZE] = $value;
                    break;
                case self::SETTING_INITIAL_WINDOW_SIZE:
                    $this->remoteSettings[self::SETTING_INITIAL_WINDOW_SIZE] = $value;
                    break;
                case self::SETTING_MAX_CONCURRENT_STREAMS:
                    $this->remoteSettings[self::SETTING_MAX_CONCURRENT_STREAMS] = $value;
                    break;
                case self::SETTING_MAX_FRAME_SIZE:
                    if ($value < 16384) {
                        throw new ConnectionException('Max frame size must be at least 16384 bytes', Frame::PROTOCOL_ERROR);
                    }
                    
                    $this->remoteSettings[self::SETTING_MAX_FRAME_SIZE] = $value;
                    break;
                case self::SETTING_MAX_HEADER_LIST_SIZE:
                    $this->remoteSettings[self::SETTING_MAX_HEADER_LIST_SIZE] = $value;
                    break;
            }
        }
        
        yield $this->writeFrame(new Frame(Frame::SETTINGS, '', Frame::ACK), 100);
    }

    protected function processWindowUpdateFrame(Frame $frame)
    {
        $increment = unpack('N', "\x7F\xFF\xFF\xFF" & $frame->data)[1];
        
        if ($increment < 1) {
            throw new ConnectionException('WINDOW_UPDATE increment must be positive and bigger than 0', Frame::PROTOCOL_ERROR);
        }
        
        $this->outputWindow += $increment;
        
        if ($this->outputDefer) {
            $defer = $this->outputDefer;
            $this->outputDefer = null;
            
            $defer->resolve($increment);
        }
    }

    public function getOutputWindow(): int
    {
        return $this->outputWindow;
    }

    public function awaitWindowUpdate(): Awaitable
    {
        if ($this->outputDefer === null) {
            $this->outputDefer = new Deferred();
        }
        
        return $this->outputDefer;
    }
    
    protected function processStreamFrame(int $stream, Frame $frame): \Generator
    {
        if ($frame->type === Frame::PRIORITY) {
            return;
        }
        
        if ($frame->type === Frame::RST_STREAM) {
            return $this->closeStream($stream);
        }
        
        if (empty($this->streams[$stream])) {
            if (!$this->client) {
                switch ($frame->type) {
                    case Frame::HEADERS:
                    case Frame::WINDOW_UPDATE:
                        $this->openServerStream($stream);
                        
                        return yield from $this->processStreamFrame($stream, $frame);
                }
            }
            
            throw new ConnectionException(\sprintf('Stream does not exist: "%s"', $stream));
        }
        
        switch ($frame->type) {
            case Frame::HEADERS:
                $this->streams[$stream]->processHeadersFrame($frame);
                break;
            case Frame::CONTINUATION:
                $this->streams[$stream]->processContinuationFrame($frame);
                break;
            case Frame::DATA:
                yield from $this->streams[$stream]->processDataFrame($frame);
                break;
            case Frame::WINDOW_UPDATE:
                $this->streams[$stream]->processWindowUpdateFrame($frame);
                break;
            default:
                $this->streams[$stream]->processFrame($frame);
        }
    }

    public function writeFrame(Frame $frame, int $priority = 0): Awaitable
    {
        return $this->writer->execute(function () use ($frame) {
            return $this->socket->write($frame->encode(0));
        }, $priority);
    }

    public function writeStreamFrame(int $stream, Frame $frame, int $priority = 0): Awaitable
    {
        return $this->writer->execute(function () use ($stream, $frame) {
            return $this->socket->write($frame->encode($stream));
        }, $priority);
    }

    public function writeStreamFrames(int $stream, array $frames, int $priority = 0): Awaitable
    {
        return $this->writer->execute(function () use ($stream, $frames) {
            $len = 0;
            
            foreach ($frames as $frame) {
                $len += yield $this->socket->write($frame->encode($stream));
            }
            
            return $len;
        }, $priority);
    }
}
