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

use KoolKode\Async\Context;
use KoolKode\Async\Disposable;
use KoolKode\Async\Channel\Channel;
use KoolKode\Async\Channel\ChannelClosedException;

/**
 * Buffers a single WebSocket text message.
 *
 * @author Martin Schröder
 */
class MessageReader implements Disposable
{
    /**
     * Maximum text message size (in bytes).
     *
     * @var int
     */
    protected $maxSize;
    
    /**
     * Has the message been compressed by the remote end?
     *
     * @var bool
     */
    protected $compressed = false;
    
    /**
     * Buffered message or stream synchronizer.
     *
     * @var mixed
     */
    protected $buffer;
    
    /**
     * Optional message compression extension.
     *
     * @var PerMessageDeflate
     */
    protected $deflate;
    
    /**
     * Optional zlib decompression context being used to inflate the message contents.
     *
     * @var resource
     */
    protected $compression;
    
    protected $flushMode;
    
    /**
     * Create a new text message reader with buffering.
     *
     * @param int $maxSize
     * @param PerMessageDeflate $deflate
     */
    public function __construct(int $maxSize, ?PerMessageDeflate $deflate = null)
    {
        $this->maxSize = $maxSize;
        $this->deflate = $deflate;
        
        if ($deflate) {
            $this->compression = $deflate->getDecompressionContext();
            $this->flushMode = $deflate->getDecompressionFlushMode();
        }
    }
    
    /**
     * Dispose of the reader due to an error condition.
     *
     * @param \Throwable $e
     */
    public function close(?\Throwable $e = null): void
    {
        try {
            if ($this->buffer instanceof Channel) {
                $this->buffer->close($e);
            }
        } finally {
            $this->buffer = null;
        }
    }
    
    public function appendTextFrame(Frame $frame): ?string
    {
        if ($this->buffer !== null) {
            throw new ConnectionException('Text frame received where continuation frame was expected', Frame::PROTOCOL_ERROR);
        }
        
        if ($this->deflate && $frame->reserved & Frame::RESERVED1) {
            if ($frame->finished) {
                $frame->data = \inflate_add($this->compression, $frame->data . "\x00\x00\xFF\xFF", $this->flushMode);
            } else {
                $frame->data = \inflate_add($this->compression, $frame->data, \ZLIB_SYNC_FLUSH);
                $this->compressed = true;
            }
        }
        
        if (\strlen($frame->data) > $this->maxSize) {
            throw new ConnectionException(\sprintf('Maximum text message size of %u bytes exceeded', $this->maxSize), Frame::MESSAGE_TOO_BIG);
        }
        
        if (!$frame->finished) {
            $this->buffer = $frame->data;
            
            return null;
        }
        
        if (!\preg_match('//u', $frame->data)) {
            throw new ConnectionException('Text message contains invalid UTF-8 data', Frame::INCONSISTENT_MESSAGE);
        }
        
        return $frame->data;
    }
    
    public function appendBinaryFrame(Frame $frame): BinaryStream
    {
        if ($this->buffer !== null) {
            throw new ConnectionException('Binary frame received where continuation frame was expected', Frame::PROTOCOL_ERROR);
        }
        
        if ($this->deflate && $frame->reserved & Frame::RESERVED1) {
            if ($frame->finished) {
                $frame->data = \inflate_add($this->compression, $frame->data . "\x00\x00\xFF\xFF", $this->flushMode);
            } else {
                $frame->data = \inflate_add($this->compression, $frame->data, \ZLIB_SYNC_FLUSH);
                $this->compressed = true;
            }
        }
        
        $channel = new Channel();
        
        if ($frame->finished) {
            $channel->close();
            
            return new BinaryStream($channel, $frame->data);
        }
        
        return new BinaryStream($this->buffer = $channel, $frame->data);
    }

    public function appendContinuationFrame(Context $context, Frame $frame): \Generator
    {
        if ($this->buffer === null) {
            throw new ConnectionException('', Frame::PROTOCOL_ERROR);
        }
        
        if ($this->compressed) {
            if ($frame->finished) {
                $frame->data = \inflate_add($this->compression, $frame->data . "\x00\x00\xFF\xFF", $this->flushMode);
            } else {
                $frame->data = \inflate_add($this->compression, $frame->data, \ZLIB_SYNC_FLUSH);
            }
        }
        
        if ($this->buffer instanceof Channel) {
            if (!$this->buffer->isClosed()) {
                try {
                    yield $this->buffer->send($context, $frame->data);
                } catch (ChannelClosedException $e) {
                    return;
                }
            }
            
            if ($frame->finished) {
                try {
                    $this->buffer->close();
                } finally {
                    $this->buffer = null;
                }
            }
            
            return;
        }
        
        if ((\strlen($this->buffer) + \strlen($frame->data)) > $this->maxSize) {
            throw new ConnectionException(\sprintf('Maximum text message size of %u bytes exceeded', $this->maxSize), Frame::MESSAGE_TOO_BIG);
        }
        
        $this->buffer .= $frame->data;
        
        if ($frame->finished) {
            if (!\preg_match('//u', $this->buffer)) {
                throw new ConnectionException('Text message contains invalid UTF-8 data', Frame::INCONSISTENT_MESSAGE);
            }
            
            try {
                return $this->buffer;
            } finally {
                $this->buffer = null;
            }
        }
    }
}
