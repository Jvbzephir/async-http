<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Event\EventEmitter;
use KoolKode\Async\Http\HttpContext;
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\Stream as IO;
use KoolKode\Async\Stream\StreamException;
use KoolKode\Async\Task;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\captureError;
use function KoolKode\Async\currentTask;
use function KoolKode\Async\eventEmitter;
use function KoolKode\Async\suspendTask;
use function KoolKode\Async\runTask;

/**
 * A transport-layer connection between two endpoints.
 * 
 * @author Martin Schröder
 */
class Connection
{
    /**
     * Connection preface that must be sent by the client.
     *
     * @var string
     */
    const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    
    /**
     * Connection preface request "body" that must be skipped in a direct upgrade.
     * 
     * @var string
     */
    const PREFACE_BODY = "SM\r\n\r\n";
    
    const MODE_CLIENT = 1;
    
    const MODE_SERVER = 2;
    
    const SETTING_HEADER_TABLE_SIZE = 0x01;
    
    const SETTING_ENABLE_PUSH = 0x02;
    
    const SETTING_MAX_CONCURRENT_STREAMS = 0x03;
    
    const SETTING_INITIAL_WINDOW_SIZE = 0x04;
    
    const SETTING_MAX_FRAME_SIZE = 0x05;
    
    const SETTING_MAX_HEADER_LIST_SIZE = 0x06;
    
    /**
     * Initial window is 64 KB.
     * 
     * @var int
     */
    const INITIAL_WINDOW_SIZE = 65536;
    
    /**
     * PING frame payload.
     * 
     * @var string
     */
    const PING_PAYLOAD = 'KoolKode';
    
    /**
     * HTTP/2 connection mode (client / server).
     * 
     * @var int
     */
    protected $mode;
    
    /**
     * Async socket connection.
     * 
     * @var DuplexStreamInterface
     */
    protected $socket;
    
    /**
     * HTTP context.
     * 
     * @var HttpContext
     */
    protected $context;
    
    /**
     * PSR logger instance.
     * 
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * Shared HPack compression context.
     * 
     * @var HPack
     */
    protected $hpack;
    
    /**
     * Event emitter instance.
     * 
     * @var EventEmitter
     */
    protected $events;
    
    /**
     * Local control flow window size.
     * 
     * @var int
     */
    protected $window = self::INITIAL_WINDOW_SIZE;
    
    /**
     * Local connection settings.
     * 
     * @var array
     */
    protected $settings = [
        self::SETTING_HEADER_TABLE_SIZE => 4096,
        self::SETTING_ENABLE_PUSH => 0,
        self::SETTING_MAX_CONCURRENT_STREAMS => 256,
        self::SETTING_INITIAL_WINDOW_SIZE => 65535,
        self::SETTING_MAX_FRAME_SIZE => 16384,
        self::SETTING_MAX_HEADER_LIST_SIZE => 16777216
    ];
    
    /**
     * Multiplexed data streams transmitted over the connection.
     * 
     * @var array
     */
    protected $streams = [];
    
    /**
     * Counter being used to open new streams (odd numbers needed in this case).
     * 
     * @var int
     */
    protected $streamCounter = 1;
    
    /**
     * Buffered write operation to be processed.
     * 
     * @var \SplPriorityQueue
     */
    protected $writeBuffer;
    
    /**
     * Task being used to sync writes to the socket.
     * 
     * @var Task
     */
    protected $writer;
    
    /**
     * Stores the type of the last frame that has been sent over the wire.
     * 
     * @var int
     */
    protected $lastSentFrameType;
    
    /**
     * Create a new HTTP/2 connection.
     * 
     * @param int $mode Client or server mode.
     * @param DuplexStreamInterface $socket
     * @param HttpContext $context
     * @param EventEmitter $events
     * @param LoggerInterface $logger
     * 
     * @throws \InvalidArgumentException When an invalid connection mode has been specified.
     */
    public function __construct(int $mode, DuplexStreamInterface $socket, HttpContext $context, EventEmitter $events, LoggerInterface $logger = NULL)
    {
        $this->socket = $socket;
        $this->events = $events;
        $this->logger = $logger;
        
        switch ($mode) {
            case self::MODE_CLIENT:
            case self::MODE_SERVER:
                $this->mode = $mode;
                break;
            default:
                throw new \InvalidArgumentException('Unknown HTTP/2 connection mode: %s', $mode);
        }
        
        $this->context = $context;
        $this->hpack = new HPack($context->getHpackContext());
        $this->writeBuffer = new \SplPriorityQueue();
    }
    
    /**
     * Dump some info about the connection.
     */
    public function __debugInfo(): array
    {
        return [
            'mode' => ($this->mode === self::MODE_CLIENT) ? 'client' : 'server',
            'window' => $this->window,
            'settings' => $this->settings,
            'socket' => $this->socket,
            'streams' => count($this->streams)
        ];
    }
    
    /**
     * Coroutine that creates an HTTP/2 client connection and sends the preface and initial settings frame.
     * 
     * @param DuplexStreamInterface $socket
     * @param HttpContext $context
     * @param LoggerInterface $logger
     * @return Connection
     */
    public static function connectClient(DuplexStreamInterface $socket, HttpContext $context, LoggerInterface $logger = NULL): \Generator
    {
        $conn = new static(self::MODE_CLIENT, $socket, $context, yield eventEmitter(), $logger);
    
        yield from $socket->write(self::PREFACE);
        yield from $conn->writeFrame(new Frame(Frame::SETTINGS, pack('nN', self::SETTING_INITIAL_WINDOW_SIZE, self::INITIAL_WINDOW_SIZE)), 500);
        
        // Disable connection-level flow control.
        yield from $conn->writeFrame(new Frame(Frame::WINDOW_UPDATE, pack('N', 0x7FFFFFFF - self::INITIAL_WINDOW_SIZE)));
        
        return $conn;
    }
    
    /**
     * Coroutine that creates an HTTP/2 server connection, validates the preface is sent by the client and processes initial settings exchange.
     * 
     * @param DuplexStreamInterface $socket
     * @param HttpContext $context
     * @param LoggerInterface $logger
     * @return Connection
     * 
     * @throws ConnectionException When the client did not send an HTTP/2 connection preface.
     */
    public static function connectServer(DuplexStreamInterface $socket, HttpContext $context, LoggerInterface $logger = NULL): \Generator
    {
        $preface = yield from IO::readBuffer($socket, strlen(self::PREFACE), true);
        
        if ($preface !== self::PREFACE) {
            throw new ConnectionException('Client did not send valid HTTP/2 connection preface');
        }
        
        $conn = new static(self::MODE_SERVER, $socket, $context, yield eventEmitter(), $logger);
        
        yield from $conn->handleServerHandshake();
        
        return $conn;
    }
    
    /**
     * Perform server handshake after initial SETTINGS frame sent by client has been processed.
     * 
     * @param Frame $settings Initial SETTINGS frame, will read next frame when not specified.
     * 
     * @throws StreamException When no initial SETTINGS frame is available.
     */
    public function handleServerHandshake(Frame $settings = NULL): \Generator
    {
        if ($settings === NULL) {
            list ($id, $settings) = yield from $this->readNextFrame();
        }
        
        if ($id !== 0 || $settings->type !== Frame::SETTINGS) {
            throw new StreamException('Missing initial settings frame');
        }
        
        yield from $this->handleFrame($settings);
        yield from $this->writeFrame(new Frame(Frame::SETTINGS, pack('nN', self::SETTING_INITIAL_WINDOW_SIZE, self::INITIAL_WINDOW_SIZE)));
        
        // Disable connection-level flow control.
        yield from $this->writeFrame(new Frame(Frame::WINDOW_UPDATE, pack('N', 0x7FFFFFFF - self::INITIAL_WINDOW_SIZE)));
    }
    
    public function getSocket(): DuplexStreamInterface
    {
        return $this->socket;
    }
    
    public function getHttpContext(): HttpContext
    {
        return $this->context;
    }
    
    public function getHPack(): HPack
    {
        return $this->hpack;
    }
    
    public function getEvents(): EventEmitter
    {
        return $this->events;
    }
    
    /**
     * Get the local flow control window size.
     */
    public function getWindow(): int
    {
        return $this->window;
    }
    
    /**
     * Increment the local flow control window size.
     * 
     * @param int $increment Increment (in bytes), negative values are permitted.
     */
    public function incrementWindow(int $increment)
    {
        $this->window += $increment;
        $this->events->emit(new WindowUpdatedEvent($increment));
    }
    
    /**
     * Coroutine that reads the next HTTP/2 frame.
     * 
     * @return array Resolves into an array containing stream identifier and frame object.
     */
    public function readNextFrame(): \Generator
    {
        $header = yield from IO::readBuffer($this->socket, 9, true);
        
        $m = NULL;
        if (preg_match("'^HTTP/(1.[0-1])\s+'i", $header, $m)) {
            throw new ConnectionException(sprintf('Received HTTP/%s response', $m[1]));
        }
        
        $length = unpack('N', "\0" . $header)[1];
        $type = ord($header[3]);
        $stream = unpack('N', "\x7F\xFF\xFF\xFF" & substr($header, 5, 4))[1];
        
        if ($length > 0) {
            if ($length > $this->settings[self::SETTING_MAX_FRAME_SIZE]) {
                $cancel = ($stream === 0);
                
                if (!$cancel) {
                    switch ($type) {
                        case Frame::HEADERS:
                        case Frame::PUSH_PROMISE:
                        case Frame::CONTINUATION:
                        case Frame::SETTINGS:
                            $cancel = true;
                            break;
                    }
                }
                
                if ($cancel) {
                    throw new ConnectionException('Frame exceeds max frame size setting', Frame::FRAME_SIZE_ERROR);
                }
                
                throw (new Http2StreamException('Frame exceeds max frame size setting', Frame::FRAME_SIZE_ERROR))->setStreamId($stream);
            }
            
            $data = yield from IO::readBuffer($this->socket, $length, true);
        } else {
            $data = '';
        }
        
        if ($this->logger) {
            $this->logger->debug('IN <{id}> {frame}', [
                'id' => $stream,
                'frame' => (string) new Frame($type, $data, ord($header[4]))
            ]);
        }
        
        return [
            $stream,
            new Frame($type, $data, ord($header[4]))
        ];
    }
    
    /**
     * Process the next HTTP/2 frame.
     * 
     * @return Returns false when the connection has been terminated.
     */
    public function handleNextFrame(): \Generator
    {
        try {
            list ($id, $frame) = yield from $this->readNextFrame();
        } catch (StreamException $e) {
            if ($this->logger) {
                $this->logger->debug('Dropped client due to socket error: {error} in {file} at line {line}', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
            
            return false;
        }
        
        try {
            if ($id === 0) {
                return yield from $this->handleFrame($frame);
            } else {
                return yield from $this->handleStreamFrame($id, $frame);
            }
        } catch (ConnectionException $e) {
            yield captureError($e);
            yield from $this->writeFrame(new Frame(Frame::GOAWAY, $e->getCode()), 1000);
          
            return false;
        } catch (StreamException $e) {
            if ($this->logger) {
                $this->logger->debug('Dropped client due to socket error: {error} in {file} at line {line}', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }

            return false;
        }
    }
    
    /**
     * Coroutine that handles an HTTP/2 frame sent to the connection.
     * 
     * @throws ConnectionException
     */
    public function handleFrame(Frame $frame): \Generator
    {
        switch ($frame->type) {
            case Frame::CONTINUATION:
                throw new ConnectionException('CONTINUATION frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::DATA:
                throw new ConnectionException('DATA frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::GOAWAY:
                return false;
            case Frame::HEADERS:
                throw new ConnectionException('HEADERS frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::PING:
                if (\strlen($frame->data) !== 8) {
                    throw new ConnectionException('PING frame payload must consist of 8 octets', Frame::FRAME_SIZE_ERROR);
                }
                
                if ($frame->flags & Frame::ACK) {
                    if ($frame->data !== self::PING_PAYLOAD) {
                        throw new ConnectionException('Invalid response to PING received', Frame::PROTOCOL_ERROR);
                    }
                } else {
                    yield from $this->writeFrame(new Frame(Frame::PING, $frame->data, Frame::ACK), 500);
                }
                break;
            case Frame::PRIORITY:
                throw new ConnectionException('PRIORITY frame must target a stream', Frame::PROTOCOL_ERROR);
            case Frame::PUSH_PROMISE:
                throw new ConnectionException('PUSH_PROMISE is not supported by this server', Frame::PROTOCOL_ERROR);
            case Frame::RST_STREAM:
                throw new ConnectionException('RST_STREAM frame must not be sent to connection', Frame::PROTOCOL_ERROR);
            case Frame::SETTINGS:
                if ($frame->flags & Frame::ACK) {
                    if (\strlen($frame->data) !== 0) {
                        throw new ConnectionException('ACK SETTINGS frame must not have a length of more than 0 bytes', Frame::FRAME_SIZE_ERROR);
                    }
                    
                    break;
                }
                
                if ((\strlen($frame->data) % 6) !== 0) {
                    throw new ConnectionException('SETTINGS frame payload length must be a multiple of 6 ', Frame::FRAME_SIZE_ERROR);
                }
                
                foreach (str_split($frame->data, 6) as $setting) {
                    list ($key, $value) = array_values(unpack('nk/Nv', $setting));
                    $this->applySetting($key, $value);
                }
                
                yield from $this->writeFrame(new Frame(Frame::SETTINGS, '', Frame::ACK), 500);
                break;
            case Frame::WINDOW_UPDATE:
                if (\strlen($frame->data) !== 4) {
                    throw new ConnectionException('WINDOW_UPDATE payload must consist of 4 bytes', Frame::FRAME_SIZE_ERROR);
                }
                
                $increment = unpack('N', "\x7F\xFF\xFF\xFF" & $frame->data)[1];
                if ($increment < 1) {
                    throw new ConnectionException('WINDOW_UPDATE increment must be positive and bigger than 0', Frame::PROTOCOL_ERROR);
                }
                
                $this->incrementWindow($increment);
                break;
        }
    }
    
    /**
     * Delegate frame handling to a stream being transmitted over the connection.
     * 
     * @param int $id
     * @param Frame $frame
     * 
     * @throws ConnectionException
     */
    public function handleStreamFrame(int $id, Frame $frame): \Generator
    {
        if (empty($this->streams[$id])) {
            switch ($frame->type) {
                case Frame::PRIORITY:
                case Frame::RST_STREAM:
                    return;
                case Frame::WINDOW_UPDATE:
                case Frame::HEADERS:
                    if (($id % 2) === 0) {
                        throw new ConnectionException('Streams opened by a client must be assigned an uneven ID', Frame::PROTOCOL_ERROR);
                    }
                    
                    $this->streams[$id] = new Stream($id, $this, yield eventEmitter(), $this->logger);
                    $this->streams[$id]->setLocalWindowSize($this->settings[self::SETTING_INITIAL_WINDOW_SIZE]);
                    break;
                default:
                    throw new ConnectionException('HEADERS frame is required', Frame::PROTOCOL_ERROR);
            }
        }
        
        return yield from $this->streams[$id]->handleFrame($frame);
    }
    
    /**
     * Coroutine that opens a new stream multiplexed over the connection.
     * 
     * @return Stream
     */
    public function openStream(): \Generator
    {
        $events = yield eventEmitter();
        
        try {
            return $this->streams[$this->streamCounter] = new Stream($this->streamCounter, $this, $events, $this->logger);
        } finally {
            $this->streamCounter += 2;
        }
    }
    
    /**
     * Close the stream with the given HTTP/2 stream identifier.
     * 
     * @param int $id
     */
    public function closeStream(int $id)
    {
        unset($this->streams[$id]);
    }
    
    /**
     * Increment the connection flow control window of the remote peer.
     *
     * @param int $increment
     * @return Generator
     */
    public function incrementRemoteWindow(int $increment): \Generator
    {
        yield from $this->writeFrame(new Frame(Frame::WINDOW_UPDATE, pack('N', $increment)), 500);
    }
    
    /**
     * Send a prioritized frame to the client.
     */
    public function writeFrame(Frame $frame, int $priority = 0): \Generator
    {
        return yield from $this->writeStreamFrame(0, $frame, $priority + 1000000);
    }

    public function writeStreamFrame(int $stream, Frame $frame, int $priority = 0): \Generator
    {
        return yield from $this->writeStreamFrames($stream, [
            $frame
        ], $priority);
    }

    public function writeStreamFrames(int $stream, array $frames, int $priority = 0): \Generator
    {
        $this->writeBuffer->insert([
            $frames,
            $stream,
            yield currentTask()
        ], $priority);
        
        if ($this->writer === NULL) {
            $this->writer = yield runTask($this->writerTask());
        }
        
        return yield suspendTask();
    }
    
    protected function writerTask(): \Generator
    {
        try {
            while (! $this->writeBuffer->isEmpty()) {
                list ($frames, $stream, $task) = $this->writeBuffer->extract();
                
                foreach ($frames as $frame) {
                    if ($this->logger) {
                        $this->logger->debug('OUT <{id}> {frame}', [
                            'id' => $stream,
                            'frame' => (string) $frame
                        ]);
                    }
                    
                    switch ($frame->type) {
                        case Frame::HEADERS:
                        case Frame::PUSH_PROMISE:
                            $this->socket->flush();
                            break;
                        case Frame::DATA:
                            if ($this->lastSentFrameType !== $frame->type) {
                                $this->socket->flush();
                            }
                            break;
                        case Frame::CONTINUATION:
                            switch ($this->lastSentFrameType) {
                                case Frame::HEADERS:
                                case Frame::PUSH_PROMISE:
                                case Frame::CONTINUATION:
                                    // No flush needed.
                                    break;
                                default:
                                    $this->socket->flush();
                            }
                            break;
                    }
                    
                    try {
                        yield from $this->socket->write($frame->encode($stream));
                    } catch (\Throwable $e) {
                        $task->getExecutor()->schedule($task->error($e));
                        
                        continue;
                    }
                    
                    $this->lastSentFrameType = $frame->type;
                }
                
                $task->getExecutor()->schedule($task->send(NULL));
            }
        } finally {
            $this->writer = NULL;
        }
    }
    
    /**
     * Coroutine that synchronizes connection settings with the remote peer.
     */
    protected function syncSettings(): \Generator
    {
        $settings = '';
        
        foreach ($this->settings as $k => $v) {
            if ($this->mode === self::MODE_SERVER) {
                switch ($k) {
                    case self::SETTING_HEADER_TABLE_SIZE:
                    case self::SETTING_MAX_HEADER_LIST_SIZE:
                        continue 2;
                }
            }
            
            $settings .= pack('nN', $k, $v);
        }
        
        yield from $this->writeFrame(new Frame(Frame::SETTINGS, $settings), 500);
    }
    
    protected function applySetting(int $key, int $value)
    {
        // TODO: Actually apply these settings!
        
        switch ($key) {
            case self::SETTING_HEADER_TABLE_SIZE:
                // Header table size is fixed at 4096 bytes.
                if ($value < 4096) {
                    throw new ConnectionException('Header table size must be at least 4096 bytes', Frame::COMPRESSION_ERROR);
                }
                break;
            case self::SETTING_INITIAL_WINDOW_SIZE:
                $this->settings[self::SETTING_INITIAL_WINDOW_SIZE] = $value;
                break;
            case self::SETTING_ENABLE_PUSH:
            case self::SETTING_MAX_CONCURRENT_STREAMS:
            case self::SETTING_MAX_FRAME_SIZE:
            case self::SETTING_MAX_HEADER_LIST_SIZE:
                $this->settings[$key] = $value;
                break;
        }
    }
}
