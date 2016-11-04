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

namespace KoolKode\Async\Http\WebSocket;

use KoolKode\Async\Stream\ReadableChannelStream;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Util\Channel;

/**
 * Reads binary messages and pipes data through a channel int a readable stream that is consumed by the application.
 * 
 * @author Martin Schröder
 */
class BinaryMessageReader
{
    /**
     * Channel being used to pipe data into a readable stream (only needed when continuation frames are received).
     * 
     * @var Channel
     */
    protected $buffer;
    
    /**
     * Optional compression WebSocket protocol extension.
     * 
     * @var PerMessageDeflate
     */
    protected $deflate;
    
    /**
     * Optional zlib decompression context being used to inflate the message contents.
     *
     * @var resource
     */
    protected $context;
    
    /**
     * Channel that delivers received messages to the application.
     * 
     * @var Channel
     */
    protected $messages;
    
    /**
     * Create a new binary message reader / streamer.
     * 
     * @param Channel $messages
     * @param PerMessageDeflate $deflate
     */
    public function __construct(Channel $messages, PerMessageDeflate $deflate = null)
    {
        $this->messages = $messages;
        $this->deflate = $deflate;
        
        if ($deflate) {
            $this->context = $deflate->getDecompressionContext();
        }
    }
    
    /**
     * Dispose of the buffer channel in case of an error condition.
     * 
     * @param \Throwable $e
     */
    public function dispose(\Throwable $e)
    {
        try {
            $this->buffer->close($e);
        } finally {
            $this->buffer = null;
        }
    }
    
    /**
     * Append a BINARY frame to the message buffer.
     * 
     * Messages that fit into a single frame will be delivered as in-memory streams without channel-based buffering.
     * 
     * @param Frame $frame Initial binary frame.
     * @return bool True when processing of the message is finished.
     */
    public function appendBinaryFrame(Frame $frame): \Generator
    {
        if ($frame->finished) {
            if ($this->deflate && $frame->reserved & Frame::RESERVED1) {
                $frame->data = \inflate_add($this->context, $frame->data . "\x00\x00\xFF\xFF", $this->deflate->getDecompressionFlushMode());
            }
            
            yield $this->messages->send(new ReadableMemoryStream($frame->data));
            
            return true;
        }
        
        $this->buffer = new Channel(16);
        
        $stream = new ReadableChannelStream($this->buffer);
        
        if ($this->deflate && $frame->reserved & Frame::RESERVED1) {
            $stream = new DecompressionStream($stream, $this->context, $this->deflate->getDecompressionFlushMode());
        }
        
        yield $this->messages->send($stream);
        
        foreach (\str_split($frame->data, 4096) as $chunk) {
            yield $this->buffer->send($chunk);
        }
        
        return false;
    }
    
    /**
     * Append a CONTINUATION frame the message buffer.
     * 
     * The payload of the frame will be chunked and sent into the channel backing the readable stream passed to the application.
     * 
     * @param Frame $frame
     * @return bool True when processing of the message is finished.
     */
    public function appendContinuationFrame(Frame $frame): \Generator
    {
        foreach (\str_split($frame->data, 4096) as $chunk) {
            yield $this->buffer->send($chunk);
        }
        
        if ($frame->finished) {
            $this->buffer->close();
            
            return true;
        }
        
        return false;
    }
}
