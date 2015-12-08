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
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\SocketException;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\newEventEmitter;
use function KoolKode\Async\readBuffer;

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
    
    const MODE_CLIENT = 1;
    
    const MODE_SERVER = 2;
    
    const SETTING_HEADER_TABLE_SIZE = 0x01;
    
    const SETTING_ENABLE_PUSH = 0x02;
    
    const SETTING_MAX_CONCURRENT_STREAMS = 0x03;
    
    const SETTING_INITIAL_WINDOW_SIZE = 0x04;
    
    const SETTING_MAX_FRAME_SIZE = 0x05;
    
    const SETTING_MAX_HEADER_LIST_SIZE = 0x06;
    
    const INITIAL_WINDOW_SIZE = 65535;
    
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
     * Create a new HTTP/2 connection.
     * 
     * @param int $mode Client or server mode.
     * @param DuplexStreamInterface $socket
     * @param EventEmitter $events
     * @param LoggerInterface $logger
     * 
     * @throws \InvalidArgumentException When an invalid connection mode has been specified.
     */
    public function __construct(int $mode, DuplexStreamInterface $socket, EventEmitter $events, LoggerInterface $logger = NULL)
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
        
        $this->hpack = new HPack();
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
     * @param LoggerInterface $logger
     * @return Connection
     */
    public static function connectClient(DuplexStreamInterface $socket, LoggerInterface $logger = NULL): \Generator
    {
        $conn = new static(self::MODE_CLIENT, $socket, yield newEventEmitter(), $logger);
    
        yield from $socket->write(self::PREFACE);
        yield from $conn->writeFrame(new Frame(Frame::SETTINGS, pack('nN', Connection::SETTING_INITIAL_WINDOW_SIZE, Stream::INITIAL_WINDOW_SIZE)), 500);
        
        return $conn;
    }
    
    /**
     * Coroutine that creates an HTTP/2 server connection, validates the preface is sent by the client and processes initial settings exchange.
     * 
     * @param DuplexStreamInterface $socket
     * @param LoggerInterface $logger
     * @return Connection
     * 
     * @throws \RuntimeException When the client did not send an HTTP/2 connection preface.
     */
    public static function connectServer(DuplexStreamInterface $socket, LoggerInterface $logger = NULL): \Generator
    {
        $preface = yield readBuffer($socket, strlen(self::PREFACE));
    
        if ($preface !== self::PREFACE) {
            throw new \RuntimeException('Client did not send valid HTTP/2 connection preface');
        }
    
        $conn = new static(self::MODE_SERVER, $socket, yield newEventEmitter(), $logger);
    
        list ($id, $frame) = yield from $conn->readNextFrame();
        if ($id !== 0 || $frame->type !== Frame::SETTINGS) {
            throw new SocketException('Missing initial settings frame');
        }
    
        yield from $conn->handleFrame($frame);
        yield from $conn->syncSettings();
    
        return $conn;
    }
    
    public function getSocket(): DuplexStreamInterface
    {
        return $this->socket;
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
        $header = yield readBuffer($this->socket, 9);
        
        if (strlen($header) !== 9) {
            throw new SocketException('Connection terminated');
        }
        
        $m = NULL;
        if (preg_match("'^HTTP/(1.[0-1])\s+'i", $header, $m)) {
            throw new ConnectionException(sprintf('Received HTTP/%s response', $m[1]));
        }
        
        $stream = unpack('N', substr($header, 5, 4))[1];
        if ($stream < 0) {
            $stream = ~$stream;
        }
        
        $type = ord($header[3]);
        $length = unpack('N', "\0" . $header)[1];
        
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
                
                throw (new StreamException('Frame exceeds max frame size setting', Frame::FRAME_SIZE_ERROR))->setStreamId($stream);
            }
            
            $data = yield readBuffer($this->socket, $length);
        } else {
            $data = '';
        }
        
        if ($this->logger) {
            $this->logger->debug('IN <{id}> {frame}', [
                'id' => $stream,
                'frame' => (string) new Frame(ord($header[3]), $data, ord($header[4]))
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
        } catch (SocketException $e) {
            return false;
        }
        
        if ($id === 0) {
            try {
                return yield from $this->handleFrame($frame);
            } catch (SocketException $e) {
                return false;
            } catch (ConnectionException $e) {
                yield from $this->writeFrame(new Frame(Frame::GOAWAY, $e->getCode()), 1000);
                
                return false;
            }
        }
        
        return yield from $this->handleStreamFrame($id, $frame);
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
                if (strlen($frame->data) !== 8) {
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
                    if (strlen($frame->data) !== 0) {
                        throw new ConnectionException('ACK SETTINGS frame must not have a length of more than 0 bytes', Frame::FRAME_SIZE_ERROR);
                    }
                    
                    break;
                }
                
                if ((strlen($frame->data) % 6) !== 0) {
                    throw new ConnectionException('SETTINGS frame payload length must be a multiple of 6 ', Frame::FRAME_SIZE_ERROR);
                }
                
                foreach (str_split($frame->data, 6) as $setting) {
                    list ($key, $value) = array_values(unpack('nk/Nv', $setting));
                    
                    $this->applySetting($key, $value);
                }
                
                yield from $this->writeFrame(new Frame(Frame::SETTINGS, '', Frame::ACK), 500);
                break;
            case Frame::WINDOW_UPDATE:
                if (strlen($frame->data) !== 4) {
                    throw new ConnectionException('WINDOW_UPDATE payload must consist of 4 bytes', Frame::FRAME_SIZE_ERROR);
                }
                
                $increment = unpack('N', "\0" . $frame->data)[1];
                if ($increment < 1) {
                    throw new ConnectionException('WINDOW_UPDATE increment must be positive and not 0', Frame::PROTOCOL_ERROR);
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
                case Frame::WINDOW_UPDATE:
                case Frame::RST_STREAM:
                    return;
                case Frame::HEADERS:
                    if (($id % 2) === 0) {
                        throw new ConnectionException('Streams opened by a client must be assigned an uneven ID', Frame::PROTOCOL_ERROR);
                    }
                    
                    $this->streams[$id] = new Stream($id, $this, yield newEventEmitter(), $this->logger);
                    break;
                default:
                    throw new ConnectionException('HEADERS frame is required', Frame::PROTOCOL_ERROR);
            }
        }
        
        return yield from $this->streams[$id]->handleFrame($frame);
    }
    
    /**
     * Coroutine that opens a new stream multiplexed ogver the connection.
     * 
     * @return Stream
     */
    public function openStream(): \Generator
    {
        for ($i = (($this->mode === self::MODE_CLIENT) ? 1 : 2); $i < 9999; $i += 2) {
            if (isset($this->streams[$i])) {
                continue;
            }
            
            return $this->streams[$i] = new Stream($i, $this, yield newEventEmitter(), $this->logger);
        }
        
        throw new \RuntimeException('Maximum number of concurrent streams exceeded');
    }
    
    /**
     * Close the stream with the given HTTP/2 stream identifier.
     * 
     * @param int $id
     */
    public function closeStream(int $id)
    {
        unset($this->streams[$id]);
        
        if ($this->mode === self::MODE_CLIENT && empty($this->streams)) {
            $this->socket->close();
        }
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
        if ($this->logger) {
            $this->logger->debug('OUT <{id}> {frame}', [
                'id' => 0,
                'frame' => (string) $frame
            ]);
        }
        
        return yield from $this->socket->write($frame->encode(0), $priority + 1000);
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
            case self::SETTING_ENABLE_PUSH:
            case self::SETTING_MAX_CONCURRENT_STREAMS:
            case self::SETTING_INITIAL_WINDOW_SIZE:
            case self::SETTING_MAX_FRAME_SIZE:
            case self::SETTING_MAX_HEADER_LIST_SIZE:
                break;
        }
    }
}
