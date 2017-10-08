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
use KoolKode\Async\Promise;
use KoolKode\Async\Concurrent\Executor;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\WritableStream;

class FramedStream implements Disposable
{
    protected $readStream;
    
    protected $writeStream;
    
    protected $executor;
    
    protected $client;
    
    protected $maxFrameSize;
    
    public function __construct(ReadableStream $readStream, WritableStream $writeStream, bool $client = false, int $maxFrameSize = 0xFFFF)
    {
        $this->readStream = $readStream;
        $this->writeStream = $writeStream;
        $this->client = $client;
        $this->maxFrameSize = $maxFrameSize;
        
        $this->executor = new Executor();
    }
    
    /**
     * {@inheritdoc}
     */
    public function close(?\Throwable $e = null): void
    {
        $this->readStream->close($e);
        $this->writeStream->close($e);
    }
    
    public function readFrame(Context $context): \Generator
    {
        $header = yield $this->readStream->readBuffer($context, 2);
        $byte1 = \ord($header[0]);
        $byte2 = \ord($header[1]);
        
        $masked = ($byte2 & Frame::MASKED) ? true : false;
        
        if ($this->client && $masked) {
            throw new ConnectionException('Received masked frame from server', Frame::PROTOCOL_ERROR);
        }
        
        if (!$this->client && !$masked) {
            throw new ConnectionException('Received unmasked frame from client', Frame::PROTOCOL_ERROR);
        }
        
        // Parse extended length fields:
        $len = $byte2 & Frame::LENGTH;
        
        if ($len === 0x7E) {
            $len = \unpack('n', yield $this->readStream->readBuffer($context, 2))[1];
        } elseif ($len === 0x7F) {
            $lp = \unpack('N2', yield $this->readStream->readBuffer($context, 8));
            
            // 32 bit int check:
            if (\PHP_INT_MAX === 0x7FFFFFFF) {
                if ($lp[1] !== 0 || $lp[2] < 0) {
                    throw new ConnectionException('Max payload size exceeded', Frame::MESSAGE_TOO_BIG);
                }
                
                $len = $lp[2];
            } else {
                $len = $lp[1] << 32 | $lp[2];
                
                if ($len < 0) {
                    throw new ConnectionException('Cannot use most significant bit in 64 bit length field', Frame::MESSAGE_TOO_BIG);
                }
            }
        }
        
        if ($len < 0) {
            throw new ConnectionException('Payload length must not be negative', Frame::MESSAGE_TOO_BIG);
        }
        
        if ($len > $this->maxFrameSize) {
            throw new ConnectionException(\sprintf('Maximum frame size of %u bytes exceeded', $this->maxFrameSize), Frame::MESSAGE_TOO_BIG);
        }
        
        // Read and unmask frame data.
        if ($this->client) {
            $data = yield $this->readStream->readBuffer($context, $len);
        } else {
            $mask = yield $this->readStream->readBuffer($context, 4);
            $data = (yield $this->readStream->readBuffer($context, $len)) ^ \str_pad($mask, $len, $mask, STR_PAD_RIGHT);
        }
        
        return new Frame($byte1 & Frame::OPCODE, $data, ($byte1 & Frame::FINISHED) ? true : false, $byte1 & Frame::RESERVED);
    }
    
    public function writeFrame(Context $context, Frame $frame, int $priority = 0): Promise
    {
        return $this->executor->submit($context, $this->writeTask($context, $frame->encode($this->client)), $priority);
    }

    public function writeFrames(Context $context, array $frames, int $priority = 0): Promise
    {
        $buffer = '';
        
        foreach ($frames as $frame) {
            $buffer .= $frame->encode($this->client);
        }
        
        return $this->executor->submit($context, $this->writeTask($context, $buffer), $priority);
    }

    protected function writeTask(Context $context, string $buffer): \Generator
    {
        return yield $this->writeStream->write($context, $buffer);
    }
}
