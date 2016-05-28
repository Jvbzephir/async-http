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

use KoolKode\Async\Channel;
use KoolKode\Async\Event\EventEmitter;
use KoolKode\Async\Http\Header\AcceptEncodingHeader;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpMessage;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http1\DeflateInputStream;
use KoolKode\Async\Http\Http1\InflateInputStream;
use KoolKode\Async\Stream\InputStreamInterface;
use KoolKode\Async\Stream\Stream as IO;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\awaitAll;
use function KoolKode\Async\currentTask;
use function KoolKode\Async\eventEmitter;

/**
 * A bidirectional flow of frames within the HTTP/2 connection.
 * 
 * @author Martin Schröder
 */
class Stream
{
    /**
     * All streams start in the "idle" state.
     * 
     * @var int
     */
    const IDLE = 1;
    
    /**
     * A stream in the "reserved (local)" state is one that has been promised by sending a PUSH_PROMISE frame. A PUSH_PROMISE frame
     * reserves an idle stream by associating the stream with an open stream that was initiated by the remote peer (see Section 8.2).
     * 
     * @var int
     */
    const RESERVED_LOCAL = 2;
    
    /**
     * A stream in the "reserved (remote)" state has been reserved by a remote peer.
     * 
     * @var int
     */
    const RESERVED_REMOTE = 3;
    
    /**
     * A stream in the "open" state may be used by both peers to send frames of any type. In this state, sending peers observe
     * advertised stream-level flow-control limits (Section 5.2).
     * 
     * @var int
     */
    const OPEN = 4;
    
    /**
     * A stream that is in the "half-closed (local)" state cannot be used for sending frames other than WINDOW_UPDATE, PRIORITY, and RST_STREAM.
     * 
     * @var int
     */
    const HALF_CLOSED_LOCAL = 5;
    
    /**
     * A stream that is "half-closed (remote)" is no longer being used by the peer to send frames. In this state, an endpoint is no longer
     * obligated to maintain a receiver flow-control window.
     * 
     * @var int
     */
    const HALF_CLOSED_REMOTE = 6;
    
    /**
     * The "closed" state is the terminal state.
     * 
     * @var int
     */
    const CLOSED = 7;
    
    const MAX_HEADER_SIZE = 16384;
    
    /**
     * Initial windows size is 64 KB.
     * 
     * @var int
     */
    const INITIAL_WINDOW_SIZE = 65535;
    
    /**
     * Identifier of the stream instance.
     * 
     * @var int
     */
    protected $id;
    
    /**
     * Current stream state.
     * 
     * @var int
     */
    protected $state = self::IDLE;
    
    /**
     * HTTP/2 stream priority.
     * 
     * @var int
     */
    protected $priority = 0;
    
    /**
     * Flow control window size, the window determines the number of bytes the remote peer is willing to receive.
     * 
     * @var int
     */
    protected $window = self::INITIAL_WINDOW_SIZE;
    
    /**
     * Connection.
     * 
     * @var Connection
     */
    protected $conn;
    
    /**
     * Async socket stream.
     * 
     * @var DuplexStreamInterface
     */
    protected $socket;
    
    /**
     * Async event emitter.
     * 
     * @var EventEmitter
     */
    protected $events;
    
    /**
     * PSR logger instance.
     * 
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * Shared HPack compression context being used by the connection.
     * 
     * @var HPack
     */
    protected $hpack;
    
    protected $tasks;
    
    protected $started;
    
    /**
     * Has END_STREAM flag been received?
     *
     * @var bool
     */
    protected $ended = false;
    
    /**
     * Keeps a reference to the preceeding frame.
     *
     * @var Frame
     */
    protected $lastFrame;
    
    /**
     * Buffered compressed header data.
     *
     * @var string
     */
    protected $headers;
    
    /**
     * HTTP/2 message body stream.
     *
     * @var Http2InputStream
     */
    protected $body;
    
    public function __construct(int $id, Connection $conn, EventEmitter $events, LoggerInterface $logger = NULL)
    {
        $this->id = $id;
        $this->conn = $conn;
        $this->events = $events;
        $this->logger = $logger;
        $this->started = microtime(true);
        $this->tasks = new \SplObjectStorage();
        $this->socket = $conn->getSocket();
        $this->hpack = $conn->getHPack();
    }
    
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'headerBuffer' => sprintf('%u bytes buffered', strlen($this->headers))
        ];
    }
    
    public function close()
    {
        try {
            foreach ($this->tasks as $task) {
                $task->cancel();
            }
        } finally {
            $this->tasks = new \SplObjectStorage();
        }
    }
    
    public function getId(): int
    {
        return $this->id;
    }
    
    public function getEvents(): EventEmitter
    {
        return $this->events;
    }
    
    public function changeState(int $state)
    {
        $allowed = [];
        
        switch ($this->state) {
            case self::IDLE:
                $allowed = [
                    self::RESERVED_LOCAL,
                    self::RESERVED_REMOTE,
                    self::OPEN
                ];
                break;
            case self::RESERVED_LOCAL:
                $allowed = [
                    self::HALF_CLOSED_REMOTE,
                    self::CLOSED
                ];
                break;
            case self::RESERVED_REMOTE:
                $allowed = [
                    self::HALF_CLOSED_LOCAL,
                    self::CLOSED
                ];
                break;
            case self::OPEN:
                $allowed = [
                    self::HALF_CLOSED_REMOTE,
                    self::HALF_CLOSED_LOCAL,
                    self::CLOSED
                ];
                break;
            case self::HALF_CLOSED_REMOTE:
                $allowed = [
                    self::CLOSED
                ];
                break;
            case self::HALF_CLOSED_LOCAL:
                $allowed = [
                    self::CLOSED
                ];
                break;
        }
        
        if (!in_array($state, $allowed, true)) {
            throw new Http2StreamException($this->id, sprintf('Invalid state transition from %u to %u', $this->state, $state), Frame::PROTOCOL_ERROR);
        }
        
        $this->state = $state;
    }
    
    protected function assertState(int ...$allowed)
    {
        if (!in_array($this->state, $allowed, true)) {
            throw new Http2StreamException('Operation permitted by current stream state', Frame::PROTOCOL_ERROR);
        }
    }
    
    public function getPriority(): int
    {
        return $this->priority;
    }
    
    public function setPriority(int $priority)
    {
        $this->priority = max(1, min(256, $priority));
    }
    
    public function handleFrame(Frame $frame): \Generator
    {
        if ($this->body === NULL) {
            $this->body = new Http2InputStream($this, yield eventEmitter(true), ($this->id % 2) !== 0);
        }
        
        try {
            if ($this->lastFrame !== NULL && $this->lastFrame->type === Frame::CONTINUATION) {
               if (!($this->lastFrame->flags & Frame::END_HEADERS) && $frame->type !== Frame::CONTINUATION) {
                   throw new Http2StreamException('CONTINUATION frame expected', Frame::PROTOCOL_ERROR);
               }
            }
            
            switch ($frame->type) {
                case Frame::CONTINUATION:
                    $this->assertState(self::OPEN);
                    
                    if ($this->lastFrame !== NULL) {
                        switch ($this->lastFrame->type) {
                            case Frame::HEADERS:
                            case Frame::CONTINUATION:
                                if ($this->lastFrame->flags & Frame::END_HEADERS) {
                                    throw new Http2StreamException('Detected CONTINUATION frame after END_HEADERS', Frame::PROTOCOL_ERROR);
                                }
                                break;
                            default:
                                throw new Http2StreamException('CONTINUATION frame must be preceeded by HEADERS or CONTINUATION', Frame::PROTOCOL_ERROR);
                        }
                    }
                    
                    $this->headers .= $frame->data;
                    
                    if ($frame->flags & Frame::END_HEADERS) {
                        if ($this->body->eof()) {
                            $this->changeState(self::HALF_CLOSED_REMOTE);
                        }
                        
                        yield from $this->handleMessage();
                    }
                    
                    break;
                case Frame::DATA:
                    $this->assertState(self::OPEN, self::HALF_CLOSED_REMOTE);

                    $data = $frame->data;
                    
                    if ($frame->flags & Frame::PADDED) {
                        $data = substr($data, 1, -1 * ord($data[0]));
                    }
                    
                    $this->body->appendData($data, $frame->flags & Frame::END_STREAM, strlen($frame->data) - strlen($data));
                    
                    if ($frame->flags & Frame::END_STREAM) {
                        $this->changeState(self::HALF_CLOSED_REMOTE);
                    }
                    
                    if ($this->headers !== '') {
                        yield from $this->handleMessage();
                    }
                    
                    break;
                case Frame::GOAWAY:
                    throw new ConnectionException('GOAWAY must be sent to a connection', Frame::PROTOCOL_ERROR);
                case Frame::HEADERS:
                    $this->assertState(self::IDLE, self::RESERVED_LOCAL, self::OPEN, self::HALF_CLOSED_REMOTE);
                    
                    $this->changeState(self::OPEN);
                    
                    $data = $frame->data;
                    
                    if ($frame->flags & Frame::PADDED) {
                        $data = substr($data, 1, -1 * ord($data[0]));
                    }
                    
                    if ($frame->flags & Frame::PRIORITY_FLAG) {
                        $this->setPriority(ord(substr($data, 4, 1)) + 1);
                        $data = substr($data, 5);
                    }
                    
                    $this->headers = $data;
                    
                    if ($frame->flags & Frame::END_HEADERS) {
                        if ($frame->flags & Frame::END_STREAM) {
                            $this->body->setEof(true);
                            $this->changeState(self::HALF_CLOSED_REMOTE);
                            
                            yield from $this->handleMessage();
                        }
                    }
                    
                    break;
                case Frame::PING:
                    throw new ConnectionException('PING frame must not be sent to a stream', Frame::PROTOCOL_ERROR);
                case Frame::PRIORITY:
                    if (strlen($frame->data) !== 5) {
                        throw new Http2StreamException('PRIORITY frame does not consist of 5 bytes', Frame::FRAME_SIZE_ERROR);
                    }
                    
                    $this->setPriority(ord(substr($frame->data, 4, 1)) + 1);
                    break;
                case Frame::PUSH_PROMISE:
                    break;
                case Frame::RST_STREAM:
                    try {
                        foreach ($this->tasks as $task) {
                            $task->cancel();
                        }
                    } finally {
                        $this->tasks = new \SplObjectStorage();
                    }
                    
                    if ($this->state === self::IDLE) {
                        throw new ConnectionException('Cannot reset stream in idle state', Frame::PROTOCOL_ERROR);
                    }
                    
                    $this->conn->closeStream($this->id);
                    break;
                case Frame::SETTINGS:
                    throw new ConnectionException('SETTINGS frames must not be sent to an open stream', Frame::PROTOCOL_ERROR);
                case Frame::WINDOW_UPDATE:
                    if (strlen($frame->data) !== 4) {
                        throw new ConnectionException('WINDOW_UPDATE payload must consist of 4 bytes', Frame::FRAME_SIZE_ERROR);
                    }
                    
                    $increment = unpack('N', "\x7F\xFF\xFF\xFF" & $frame->data)[1];
                    if ($increment < 1) {
                        throw new Http2StreamException('WINDOW_UPDATE increment must be positive and not 0', Frame::PROTOCOL_ERROR);
                    }
                    
                    $this->window += $increment;
                    $this->events->emit(new WindowUpdatedEvent($increment));
                    
                    break;
            }
        } finally {
            $this->lastFrame = $frame;
        }
    }
    
    public function writeFrame(Frame ...$frames): \Generator
    {
        $data = '';
        $boost = 0;
        
        foreach ($frames as $frame) {
            $data .= $frame->encode($this->id);
            
            switch($frame->type) {
                case Frame::RST_STREAM:
                    $boost += 256;
                case Frame::PRIORITY:
                case Frame::SETTINGS:
                case Frame::WINDOW_UPDATE:
                    $boost++;
            }
        }
        
        if ($this->logger) {
            foreach ($frames as $frame) {
                $this->logger->debug('OUT <{id}> {frame}', [
                    'id' => $this->id,
                    'frame' => (string) $frame
                ]);
            }
        }
        
        // TODO: Prioritize writes within Connection to allow flushing and byte alignment.
        
        return yield from $this->socket->write($data, $this->priority + $boost);
    }
    
    protected function handleMessage(): \Generator
    {
        try {
            $headers = $this->hpack->decode($this->headers);
        } finally {
            $this->headers = '';
        }
        
        if (empty($headers)) {
            throw new Http2StreamException('Invalid HTTP headers received', Frame::COMPRESSION_ERROR);
        }
        
        $event = new MessageReceivedEvent($this, $headers, $this->body, $this->started);
        
        if ($event->getHeaderValue(':status') !== '') {
            $encoding = strtolower($event->getHeaderValue('content-encoding'));
            
            switch ($encoding) {
                case '':
                    // Uncompressed response body...
                    break;
                case 'gzip':
                    $event->body = yield from InflateInputStream::open($event->body, InflateInputStream::GZIP);
                    break;
                case 'deflate':
                    $event->body = yield from InflateInputStream::open($event->body, InflateInputStream::DEFLATE);
                    break;
                default:
                    throw new \RuntimeException(sprintf('Unsupported content encoding: "%s"', $encoding));
            }
        }
        
        $this->events->emit($event);
        $this->conn->getEvents()->emit($event);
    }
    
    protected function incrementLocalWindow(int $delta)
    {
        $this->window += $delta;
    
        $this->conn->incrementWindow($delta);
        $this->events->emit(new WindowUpdatedEvent($delta));
    }
    
    public function setLocalWindowSize(int $size)
    {
        $this->window = $size;
    }
    
    /**
     * Increment the flow control window of the remote peer. 
     * 
     * This method will also increment the connection's remote flow control window!
     * 
     * @param int $increment
     * @return Generator
     */
    public function incrementRemoteWindow(int $increment): \Generator
    {
        $task = yield currentTask();
        $this->tasks->attach($task);
        
        try {
            yield from $this->conn->incrementRemoteWindow($increment);
            yield from $this->writeFrame(new Frame(Frame::WINDOW_UPDATE, pack('N', $increment)));
        } finally {
            $this->tasks->detach($task);
        }
    }
    
    public function sendRequest(HttpRequest $request): \Generator
    {
        $uri = $request->getUri();
        $path = '/' . ltrim($request->getRequestTarget(), '/');
        
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
        
        if (InflateInputStream::isAvailable()) {
            $headers['accept-encoding'] = [
                'gzip, deflate'
            ];
        }
        
        $in = yield from $request->getBody()->getInputStream();
        
        yield from $this->sendHeaders($request, $headers, !$in->eof());
        yield from $this->sendBody($request, $in);
        
        if ($this->logger) {
            $this->logger->debug('<< {method} {path} HTTP/{version}', [
                'method' => $request->getMethod(),
                'path' => $path,
                'version' => $request->getProtocolVersion()
            ]);
        }
        
        return yield from $this->events->await(MessageReceivedEvent::class);
    }

    public function sendResponse(HttpRequest $request, HttpResponse $response, float $started = NULL): \Generator
    {
        if ($started === NULL) {
            $started = microtime(true);
        }
        
        try {
            $headers = [
                ':status' => [
                    (string) $response->getStatusCode()
                ]
            ];
            
            $in = yield from $response->getBody()->getInputStream();
            
            $response = $response->withoutHeader('Content-Encoding');
            
            if (!$in->eof() && DeflateInputStream::isAvailable()) {
                foreach (AcceptEncodingHeader::fromMessage($request)->getEncodings() as $encoding) {
                    switch ($encoding->getName()) {
                        case 'gzip':
                            $type = DeflateInputStream::GZIP;
                            break;
                        case 'deflate':
                            $type = DeflateInputStream::DEFLATE;
                            break;
                        default:
                            continue 2;
                    }
                    
                    $in = yield from DeflateInputStream::open($in, $type);
                    $response = $response->withHeader('Content-Encoding', $encoding->getName());
                    
                    break;
                }
            }
            
            yield from $this->sendHeaders($response, $headers, !$in->eof());
            yield from $this->sendBody($response, $in);
            
            if ($this->logger) {
                $this->logger->debug('<< HTTP/{version} {status} {reason} << {duration} ms', [
                    'version' => $response->getProtocolVersion(),
                    'status' => $response->getStatusCode(),
                    'reason' => $response->getReasonPhrase() ?  : Http::getReason($response->getStatusCode()),
                    'duration' => round((microtime(true) - $started) * 1000)
                ]);
            }
        } finally {
            $this->conn->closeStream($this->id);
        }
    }
    
    protected function sendHeaders(HttpMessage $message, array $headers, bool $hasBody = true): \Generator
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
        
        foreach (array_change_key_case($message->getHeaders(), CASE_LOWER) as $k => $v) {
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
        $flags = Frame::END_HEADERS | ($hasBody ? Frame::NOFLAG : Frame::END_STREAM);
        
        // Header size: 9 byte general header (optional: +4 bytes for stream dependency, +1 byte for weight).
        $chunkSize = 4096 - 9;
        
        if (strlen($headers) > $chunkSize) {
            $parts = str_split($headers, $chunkSize);
            $frames = [
                new Frame(Frame::HEADERS, $parts[0])
            ];
            
            for ($size = count($parts) - 2, $i = 1; $i < $size; $i++) {
                $frames[] = new Frame(Frame::CONTINUATION, $parts[$i]);
            }
            
            $frames[] = new Frame(Frame::CONTINUATION, $parts[count($parts) - 1], $flags);
            
            // Send all frames in one batch to ensure no concurrent writes to the socket take place.
            yield from $this->writeFrame(...$frames);
        } else {
            yield from $this->writeFrame(new Frame(Frame::HEADERS, $headers, $flags));
        }
    }
    
    protected function sendBody(HttpMessage $message, InputStreamInterface $in): \Generator
    {
        try {
            if (!$in->eof()) {
                $channel = new Channel(8);
                
                yield awaitAll([
                    $this->readBodyData($in, $channel),
                    $this->writeBodyFrames($channel)
                ]);
            }
        } finally {
            $in->close();
        }
    }
    
    protected function readBodyData(InputStreamInterface $in, Channel $channel)
    {
        // Header length is 9 bytes (no padding used).
        $chunkSize = 4096 - 9;
        
        $eof = $in->eof();
        
        while (!$eof) {
            $window = min($this->window, $this->conn->getWindow());
            $len = min($chunkSize, $window);
            
            if ($len < $chunkSize) {
                if ($this->window < 1) {
                    yield from $this->events->await(WindowUpdatedEvent::class);
                } else {
                    yield from $this->conn->getEvents()->await(WindowUpdatedEvent::class);
                }
                
                continue;
            }
            
            // Reduce local flow control window prior to actually reading data...
            $this->incrementLocalWindow(-1 * $len);
            
            $chunk = yield from IO::readBuffer($in, $len, true);
            $eof = $in->eof();
            
            // Increase local flow control window in case response body does not return the desired number of bytes.
            $delta = $len - strlen($chunk);
            if ($delta > 0) {
                $this->incrementLocalWindow($delta);
            }
            
            yield from $channel->send(new Frame(Frame::DATA, $chunk, $eof ? Frame::END_STREAM : Frame::NOFLAG));
        }
        
        $channel->shutdown();
    }
    
    protected function writeBodyFrames(Channel $channel)
    {
        while (NULL !== ($frame = yield from $channel->receive(false))) {
            yield from $this->writeFrame($frame);
        }
    }
}
