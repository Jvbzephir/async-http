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

class BinaryMessageReader
{
    protected $buffer;
    
    protected $deflate;
    
    protected $messages;
    
    public function __construct(Channel $messages, PerMessageDeflate $deflate = null)
    {
        $this->messages = $messages;
        $this->deflate = $deflate;
    }
    
    public function dispose(\Throwable $e)
    {
        try {
            $this->buffer->close($e);
        } finally {
            $this->buffer = null;
        }
    }
    
    public function appendBinaryFrame(Frame $frame): \Generator
    {
        if ($frame->finished) {
            if ($this->deflate && $frame->reserved & Frame::RESERVED1) {
                $frame->data = \inflate_add($this->deflate->getDecompressionContext(), $frame->data . "\x00\x00\xFF\xFF", $this->deflate->getDecompressionFlushMode());
            }
            
            yield $this->messages->send(new ReadableMemoryStream($frame->data));
            
            return true;
        }
        
        $this->buffer = new Channel(16);
        
        $stream = new ReadableChannelStream($this->buffer);
        
        if ($this->deflate && $frame->reserved & Frame::RESERVED1) {
            $stream = new DecompressionStream($stream, $this->deflate->getDecompressionContext(), $this->deflate->getDecompressionFlushMode());
        }
        
        yield $this->messages->send($stream);
        
        foreach (\str_split($frame->data, 4096) as $chunk) {
            yield $this->buffer->send($chunk);
        }
        
        return false;
    }
    
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
