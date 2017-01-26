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

use KoolKode\Async\Awaitable;
use KoolKode\Async\Failure;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\ReadableStream;

/**
 * Message writer that takes care of permessa-deflate compression.
 * 
 * @author Martin Schröder
 */
class CompressedMessageWriter extends MessageWriter
{
    /**
     * Deflate WebSocket protocol extension.
     * 
     * @var PerMessageDeflate
     */
    protected $deflate;
    
    /**
     * Create a new compression-enabled message writer.
     * 
     * @param SocketStream $socket
     * @param bool $client
     * @param PerMessageDeflate $deflate
     */
    public function __construct(SocketStream $socket, bool $client, PerMessageDeflate $deflate)
    {
        parent::__construct($socket, $client);
        
        $this->deflate = $deflate;
    }

    /**
     * {@inheritdoc}
     */
    public function sendText(string $text, int $priority = 0): Awaitable
    {
        if (!\preg_match('//u', $text)) {
            return new Failure(new \InvalidArgumentException('Message is not UTF-8 encoded'));
        }
        
        return $this->writer->submit(function () use ($text) {
            $type = Frame::TEXT;
            $reserved = Frame::RESERVED1;
            $context = $this->deflate->getCompressionContext();
            $chunks = \str_split($text, 4092);
            
            for ($size = \count($chunks) - 1, $i = 0; $i < $size; $i++) {
                $chunk = \deflate_add($context, $chunks[$i], \ZLIB_SYNC_FLUSH);
                
                yield $this->writeFrame(new Frame($type, $chunk, false, $reserved));
                
                $type = Frame::CONTINUATION;
                $reserved = 0;
            }
            
            $chunk = \substr(\deflate_add($context, $chunks[$i], $this->deflate->getCompressionFlushMode()), 0, -4);
            
            yield $this->writeFrame(new Frame($type, $chunk, true, $reserved));
        }, $priority);
    }

    /**
     * {@inheritdoc}
     */
    public function sendBinary(ReadableStream $stream, int $priority = 0): Awaitable
    {
        return $this->writer->submit(function () use ($stream) {
            $type = Frame::BINARY;
            $reserved = Frame::RESERVED1;
            $context = $this->deflate->getCompressionContext();
            $len = 0;
            
            try {
                $chunk = yield $stream->readBuffer(4092);
                
                while (null !== ($next = yield $stream->readBuffer(4092))) {
                    $chunk = \deflate_add($context, $chunk, \ZLIB_SYNC_FLUSH);
                    
                    $len += yield $this->writeFrame(new Frame($type, $chunk, false, $reserved));
                    
                    $chunk = $next;
                    $type = Frame::CONTINUATION;
                    $reserved = 0;
                }
                
                if ($chunk !== null) {
                    $chunk = \substr(\deflate_add($context, $chunk, $this->deflate->getCompressionFlushMode()), 0, -4);
                    
                    $len += yield $this->writeFrame(new Frame($type, $chunk, true, $reserved));
                }
                
                return $len;
            } finally {
                $stream->close();
            }
        }, $priority);
    }
}
