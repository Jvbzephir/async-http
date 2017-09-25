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

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Context;
use KoolKode\Async\Promise;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\SyncWriter;
use KoolKode\Async\Stream\WritableStream;

class FramedStream
{
    protected $readStream;
    
    protected $writeStream;

    public function __construct(ReadableStream $readStream, WritableStream $writeStream)
    {
        $this->readStream = $readStream;
        $this->writeStream = new SyncWriter($writeStream);
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function readFrame(Context $context): Promise
    {
        return $context->task($this->readFrameTask($context));
    }

    protected function readFrameTask(Context $context): \Generator
    {
        $header = yield $this->readStream->readBuffer($context, 9);
        
        $length = \unpack('N', "\x00" . $header)[1];
        $stream = \unpack('N', "\x7F\xFF\xFF\xFF" & \substr($header, 5, 4))[1];
        
        if ($length > 0) {
            $frame = new Frame(\ord($header[3]), $stream, yield $this->readStream->readBuffer($context, $length), \ord($header[4]));
        } else {
            $frame = new Frame(\ord($header[3]), $stream, '', \ord($header[4]));
        }
        
        return $frame;
    }

    public function writeFrame(Context $context, Frame $frame): Promise
    {
        return $this->writeStream->write($context, $frame->encode());
    }

    public function writeFrames(Context $context, array $frames): Promise
    {
        $buffer = '';
        
        foreach ($frames as $frame) {
            $buffer .= $frame->encode();
        }
        
        return $this->writeStream->write($context, $buffer);
    }
}
