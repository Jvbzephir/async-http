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
use KoolKode\Async\Concurrent\ChannelClosedException;
use KoolKode\Async\Concurrent\Synchronizer;

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
        if ($this->buffer instanceof Synchronizer) {
            $this->buffer->close($e);
        } else {
            $this->buffer = '';
        }
    }
    
    public function appendTextFrame(Frame $frame): ?string
    {
        if ($this->buffer !== null) {
            throw new ConnectionException('Text frame received where continuation frame was expected', Frame::PROTOCOL_ERROR);
        }
        
        if (\strlen($frame->data) > $this->maxSize) {
            throw new ConnectionException(\sprintf('Maximum text message size of %u bytes exceeded', $this->maxSize), Frame::MESSAGE_TOO_BIG);
        }
        
        if (!$frame->finished) {
            $this->buffer = $frame->data;
            $this->compressed = $this->deflate && (($frame->reserved & Frame::RESERVED1) ? true : false);
            
            return null;
        }
        
        if ($this->deflate && $frame->reserved & Frame::RESERVED1) {
            $frame->data = \inflate_add($this->compression, $frame->data . "\x00\x00\xFF\xFF", $this->flushMode);
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
        
        if (\strlen($frame->data) > $this->maxSize) {
            throw new ConnectionException(\sprintf('Maximum text message size of %u bytes exceeded', $this->maxSize), Frame::MESSAGE_TOO_BIG);
        }
        
        if ($this->deflate && $frame->reserved & Frame::RESERVED1) {
            if ($frame->finished) {
                $frame->data = \inflate_add($this->compression, $frame->data . "\x00\x00\xFF\xFF", $this->flushMode);
            } else {
                $frame->data = \inflate_add($this->compression, $frame->data, \ZLIB_SYNC_FLUSH);
                $this->compressed = true;
            }
        }
        
        $sync = new Synchronizer();
        
        if ($frame->finished) {
            $sync->close();
            
            return new BinaryStream($sync, $frame->data);
        }
        
        return new BinaryStream($this->buffer = $sync, $frame->data);
    }

    public function appendContinuationFrame(Context $context, Frame $frame): \Generator
    {
        if ($this->buffer === null) {
            throw new ConnectionException('', Frame::PROTOCOL_ERROR);
        }
        
        if ((\strlen($this->buffer) + \strlen($frame->data)) > $this->maxSize) {
            throw new ConnectionException(\sprintf('Maximum text message size of %u bytes exceeded', $this->maxSize), Frame::MESSAGE_TOO_BIG);
        }
        
        if ($this->buffer instanceof Synchronizer) {
            if ($this->compressed) {
                if ($frame->finished) {
                    $frame->data = \inflate_add($this->compression, $frame->data . "\x00\x00\xFF\xFF", $this->flushMode);
                } else {
                    $frame->data = \inflate_add($this->compression, $frame->data, \ZLIB_SYNC_FLUSH);
                }
            }
            
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
        
        $this->buffer .= $frame->data;
        
        if (!$frame->finished) {
            return;
        }
        
        if ($this->compressed) {
            $this->buffer = \inflate_add($this->compressionContext, $this->buffer . "\x00\x00\xFF\xFF", $this->flushMode);
        }
        
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
