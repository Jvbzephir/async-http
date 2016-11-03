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

use KoolKode\Async\Awaitable;
use KoolKode\Async\Http\HttpBody;
use KoolKode\Async\Stream\ReadableMemoryStream;
use KoolKode\Async\Success;

/**
 * HTTP message body wrapping a string.
 * 
 * @author Martin Schröder
 */
class StringBody implements HttpBody
{
    /**
     * Message body.
     * 
     * @var string
     */
    protected $contents;

    /**
     * Create a message body around the given contents.
     * 
     * @param string $contents
     */
    public function __construct(string $contents = '')
    {
        $this->contents = $contents;
    }

    /**
     * Dump the message body.
     * 
     * @return string
     */
    public function __toString()
    {
        return $this->contents;
    }

    /**
     * {@inheritdoc}
     */
    public function isCached(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize(): Awaitable
    {
        return new Success(\strlen($this->contents));
    }

    /**
     * {@inheritdoc}
     */
    public function getReadableStream(): Awaitable
    {
        return new Success(new ReadableMemoryStream($this->contents));
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(): Awaitable
    {
        return new Success($this->contents);
    }
    
    /**
     * {@inheritdoc}
     */
    public function discard(): Awaitable
    {
        return new Success(0);
    }
}
