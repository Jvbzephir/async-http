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

class TextMessageReader
{
    protected $maxSize;
    
    protected $compressed = false;
    
    protected $buffer = '';
    
    protected $deflate;
    
    protected $messages;
    
    public function __construct(Channel $messages, int $maxSize, PerMessageDeflate $deflate = null)
    {
        $this->messages = $messages;
        $this->maxSize = $maxSize;
        $this->deflate = $deflate;
    }
    
    public function dispose(\Throwable $e)
    {
        $this->buffer = '';
    }
    
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
            $frame->data = \inflate_add($this->deflate->getDecompressionContext(), $frame->data . "\x00\x00\xFF\xFF", $this->deflate->getDecompressionFlushMode());
        }
        
        if (!\preg_match('//u', $frame->data)) {
            throw new ConnectionException('Text message contains invalid UTF-8 data', Frame::INCONSISTENT_MESSAGE);
        }
        
        yield $this->messages->send($frame->data);
        
        return true;
    }
    
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
            $this->buffer = \inflate_add($this->deflate->getDecompressionContext(), $this->buffer . "\x00\x00\xFF\xFF", $this->deflate->getDecompressionFlushMode());
        }
        
        if (!\preg_match('//u', $this->buffer)) {
            throw new ConnectionException('Text message contains invalid UTF-8 data', Frame::INCONSISTENT_MESSAGE);
        }
        
        yield $this->messages->send($this->buffer);
        
        return true;
    }
}
