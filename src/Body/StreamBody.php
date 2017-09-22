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

namespace KoolKode\Async\Http\Body;

use KoolKode\Async\Context;
use KoolKode\Async\Promise;
use KoolKode\Async\Success;
use KoolKode\Async\Http\HttpBody;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\StreamClosedException;

/**
 * HTTP message body based on an input stream.
 * 
 * @author Martin Schröder
 */
class StreamBody implements HttpBody
{
    /**
     * Body data stream.
     * 
     * @var ReadableStream
     */
    protected $stream;
    
    /**
     * Create message body backed by the given stream.
     * 
     * @param ReadableStream $stream
     */
    public function __construct(ReadableStream $stream)
    {
        $this->stream = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function isCached(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize(Context $context): Promise
    {
        return new Success($context);
    }

    /**
     * {@inheritdoc}
     */
    public function getReadableStream(Context $context): Promise
    {
        return new Success($context, $this->stream);
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(Context $context): Promise
    {
        return $context->task(function (Context $context) {
            $buffer = '';
            
            try {
                while (null !== ($chunk = yield $this->stream->read($context))) {
                    $buffer .= $chunk;
                }
            } finally {
                $this->stream->close();
            }
            
            return $buffer;
        });
    }
    
    /**
     * {@inheritdoc}
     */
    public function discard(Context $context): Promise
    {
        return $context->task(function (Context $context) {
            $len = 0;
            
            try {
                while (null !== ($chunk = yield $this->stream->read($context))) {
                    $len += \strlen($chunk);
                }
            } catch (StreamClosedException $e) {
                // Ignore closed stream during discard.
            } finally {
                $this->stream->close();
            }
            
            return $len;
        });
    }
}
