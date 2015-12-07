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
use KoolKode\Async\Stream\InputStreamInterface;

use function KoolKode\Async\noop;
use function KoolKode\Async\runTask;

/**
 * HTTP/2 body input stream utilizing flow control.
 * 
 * @author Martin Schröder
 */
class Http2InputStream implements InputStreamInterface
{
    /**
     * HTTP/2 stream.
     * 
     * @var Stream
     */
    protected $stream;
    
    /**
     * Event emitter being used to pause reads until DATA frames are received.
     * 
     * @var EventEmitter
     */
    protected $events;
    
    /**
     * Has END_STREAM flag been received yet?
     * 
     * @var bool
     */
    protected $eof = false;
    
    /**
     * Max in-memory buffer size (in bytes).
     * 
     * @var int
     */
    protected $size;
    
    /**
     * Number of bytes that have been drained from the in-memory buffer.
     * 
     * @var int
     */
    protected $drained = 0;
    
    /**
     * Read timeout (in seconds).
     * 
     * @var float
     */
    protected $timeout;
    
    /**
     * In-memory buffer.
     * 
     * @var string
     */
    protected $buffer = '';
    
    /**
     * Create a new HTTP/2 input stream backed by the given stream.
     * 
     * @param Stream $stream HTTP/2 stream.
     * @param EventEmitter $events
     * @param int $size Max in-memory buffer size (in bytes).
     * @param float $timeout Read timeout (in seconds).
     */
    public function __construct(Stream $stream, EventEmitter $events, int $size = Stream::INITIAL_WINDOW_SIZE, float $timeout = 5)
    {
        $this->stream = $stream;
        $this->events = $events;
        $this->size = $size;
        $this->timeout = $timeout;
    }
    
    public function __debugInfo()
    {
        return [
            'stream' => $this->stream->getId(),
            'buffer' => sprintf('%u / %s bytes buffered', strlen($this->buffer), $this->size),
            'drained' => $this->drained,
            'timeout' => $this->timeout
        ];
    }
    
    /**
     * Append data to the in-memory buffer.
     * 
     * @param string $data Data without padding.
     * @param bool $eof EOF flag set?
     * @param int $padLength Length of frame padding.
     */
    public function appendData(string $data, bool $eof, int $padLength = 0)
    {
        $this->buffer .= $data;
        $this->eof = $eof;
        $this->drained += $padLength;
        
        $this->events->emit(new DataReceivedEvent($data, $eof));
    }
    
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->eof = true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function eof(): \Generator
    {
        yield noop();
        
        return $this->eof && $this->buffer === '';
    }
    
    public function setEof(bool $eof)
    {
        $this->eof = $eof;
    }
    
    /**
     * {@inheritdoc}
     */
    public function read(int $length = 8192): \Generator
    {
        while ($this->buffer === '' && !$this->eof) {
            yield from $this->events->await(DataReceivedEvent::class, $this->timeout);
        }
        
        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($chunk));
        $this->drained += strlen($chunk);
        
        if (($this->eof && $this->drained > 0) || ($this->drained > 4096 && strlen($this->buffer) < $this->size)) {
            try {
                yield runTask($this->incrementRemoteWindow(min($this->size, $this->drained)), true);
            } finally {
                $this->drained = 0;
            }
        }
        
        return $chunk;
    }
    
    /**
     * Coroutine that updates the remote flow control window to have the remote peer send more data.
     * 
     * @param int $increment
     */
    protected function incrementRemoteWindow(int $increment): \Generator
    {
        if ($increment < 1) {
            return;
        }
        
        // Pause right here to avoid sync call to stream!
        yield;
        
        yield from $this->stream->incrementRemoteWindow($increment);
    }
}
