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

use KoolKode\Async\Util\Channel;

/**
 * Buffers a single WebSocket text message.
 * 
 * @author Martin Schröder
 */
class TextMessageReader
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
     * Buffered message contents.
     * 
     * @var string
     */
    protected $buffer = '';
    
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
    protected $context;
    
    /**
     * Channel that delivers received messages to the application.
     * 
     * @var Channel
     */
    protected $messages;
    
    /**
     * Create a new text message reader with buffering.
     * 
     * @param Channel $messages
     * @param int $maxSize
     * @param PerMessageDeflate $deflate
     */
    public function __construct(Channel $messages, int $maxSize, PerMessageDeflate $deflate = null)
    {
        $this->messages = $messages;
        $this->maxSize = $maxSize;
        $this->deflate = $deflate;
        
        if ($deflate) {
            $this->context = $deflate->getDecompressionContext();
        }
    }
    
    /**
     * Dispose of the reader due to an error condition.
     * 
     * @param \Throwable $e
     */
    public function dispose(\Throwable $e)
    {
        $this->buffer = '';
    }
    
    /**
     * Append the initial TEXT frame to the buffer.
     * 
     * @param Frame $frame Initial text frame.
     * @return bool True when processing of the message is finished.
     * 
     * @throws ConnectionException If size or character encoding constraints have been violated.
     */
    public function appendTextFrame(Frame $frame): \Generator
    {
        if (\strlen($frame->data) > $this->maxSize) {
            throw new ConnectionException(\sprintf('Maximum text message size of %u bytes exceeded', $this->maxSize), Frame::MESSAGE_TOO_BIG);
        }
        
        if (!$frame->finished) {
            $this->buffer = $frame->data;
            $this->compressed = $this->deflate && (($frame->reserved & Frame::RESERVED1) ? true : false);
            
            return false;
        }
        
        if ($this->deflate && $frame->reserved & Frame::RESERVED1) {
            $frame->data = \inflate_add($this->context, $frame->data . "\x00\x00\xFF\xFF", $this->deflate->getDecompressionFlushMode());
        }
        
        if (!\preg_match('//u', $frame->data)) {
            throw new ConnectionException('Text message contains invalid UTF-8 data', Frame::INCONSISTENT_MESSAGE);
        }
        
        yield $this->messages->send($frame->data);
        
        return true;
    }
    
    /**
     * Append a CONTINUATION frame to the buffer.
     * 
     * @param Frame $frame Continuation frame.
     * @return bool True when processing of the message is finished.
     * 
     * @throws ConnectionException If size or character encoding constraints have been violated.
     */
    public function appendContinuationFrame(Frame $frame): \Generator
    {
        if ((\strlen($this->buffer) + \strlen($frame->data)) > $this->maxSize) {
            throw new ConnectionException(\sprintf('Maximum text message size of %u bytes exceeded', $this->maxSize), Frame::MESSAGE_TOO_BIG);
        }
        
        $this->buffer .= $frame->data;
        
        if (!$frame->finished) {
            return false;
        }
        
        if ($this->compressed) {
            $this->buffer = \inflate_add($this->context, $this->buffer . "\x00\x00\xFF\xFF", $this->deflate->getDecompressionFlushMode());
        }
        
        if (!\preg_match('//u', $this->buffer)) {
            throw new ConnectionException('Text message contains invalid UTF-8 data', Frame::INCONSISTENT_MESSAGE);
        }
        
        yield $this->messages->send($this->buffer);
        
        return true;
    }
}
