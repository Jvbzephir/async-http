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

namespace KoolKode\Async\Http;

use Interop\Async\Awaitable;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Success;

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
     * @var InputStreamInterface
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
    public function prepareMessage(HttpMessage $message): HttpMessage
    {
        return $message;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize(): Awaitable
    {
        return new Success(NULL);
    }

    /**
     * {@inheritdoc}
     */
    public function getReadableStream(): Awaitable
    {
        return new Success($this->stream);
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(): Awaitable
    {
        return new ReadContents($this->stream);
    }
}
