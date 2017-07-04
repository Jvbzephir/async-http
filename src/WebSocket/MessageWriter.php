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
use KoolKode\Async\Concurrent\Executor;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\ReadableStream;

/**
 * Default message writer being used to frame, prioritize and transmit WebSocket messages.
 * 
 * @author Martin Schröder
 */
class MessageWriter
{
    /**
     * Underlying socket stream.
     * 
     * @var SocketStream
     */
    protected $socket;
    
    /**
     * Is the connection in client mode?
     * 
     * @var bool
     */
    protected $client;
    
    /**
     * Executor that syncs frames being written to the socket.
     *
     * @var Executor
     */
    protected $writer;
    
    /**
     * Create a new WebSocket message writer.
     * 
     * @param SocketStream $socket
     * @param bool $client
     */
    public function __construct(SocketStream $socket, bool $client)
    {
        $this->socket = $socket;
        $this->client = $client;
        
        $this->writer = new Executor();
    }
    
    /**
     * Write the given frame to the socket (does not honor concurrency control via writer).
     *
     * @param Frame $frame
     * @return int Number of transmitted bytes.
     */
    public function writeFrame(Frame $frame): Awaitable
    {
        return $this->socket->write($frame->encode($this->client ? \random_bytes(4) : null));
    }

    /**
     * Send a text message.
     * 
     * @param string $text
     * @param int $priority
     * @return int Number of transmitted bytes.
     * 
     * @throws \InvalidArgumentException When the text is not UTF-8 encoded.
     */
    public function sendText(string $text, int $priority = 0): Awaitable
    {
        if (!\preg_match('//u', $text)) {
            return new Failure(new \InvalidArgumentException('Message is not UTF-8 encoded'));
        }
        
        return $this->writer->submit(function () use ($text) {
            $type = Frame::TEXT;
            $chunks = \str_split($text, 4092);
            
            for ($size = \count($chunks) - 1, $i = 0; $i < $size; $i++) {
                yield $this->writeFrame(new Frame($type, $chunks[$i], false));
                
                $type = Frame::CONTINUATION;
            }
            
            yield $this->writeFrame(new Frame($type, $chunks[$i]));
        }, $priority);
    }

    /**
     * Stream a binary WebSocket message.
     * 
     * @param ReadableStream $stream
     * @param int $priority
     * @return int Number of transmitted bytes.
     * 
     * @throws \InvalidArgumentException When the text is not UTF-8 encoded.
     */
    public function sendBinary(ReadableStream $stream, int $priority = 0): Awaitable
    {
        return $this->writer->submit(function () use ($stream) {
            $type = Frame::BINARY;
            $len = 0;
            
            try {
                $chunk = yield $stream->readBuffer(4092);
                
                while (null !== ($next = yield $stream->readBuffer(4092))) {
                    $len += yield $this->writeFrame(new Frame($type, $chunk, false));
                    
                    $chunk = $next;
                    $type = Frame::CONTINUATION;
                }
                
                if ($chunk !== null) {
                    $len += yield $this->writeFrame(new Frame($type, $chunk));
                }
            } finally {
                $stream->close();
            }
            
            return $len;
        }, $priority);
    }
}
