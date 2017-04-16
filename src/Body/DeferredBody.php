<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Body;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\HttpBody;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Stream\ReadableChannelStream;
use KoolKode\Async\Success;
use KoolKode\Async\Util\Channel;

abstract class DeferredBody implements HttpBody
{
    /**
     * Can be used to implement some startup logic, might even be async (method must be a generator in this case).
     */
    public function start(HttpRequest $request) { }
    
    public function isCached(): bool
    {
        return false;
    }
    
    public function getSize(): Awaitable
    {
        return new Success(null);
    }
    
    /**
     * Provides an input stream that can be used to read HTTP body contents.
     *
     * @return ReadableStream
     */
    public function getReadableStream(): Awaitable
    {
        return new Success(new ReadableChannelStream(Channel::fromGenerator(8, function (Channel $channel) {
            while (null !== ($chunk = yield from $this->nextChunk())) {
                $channel->send($chunk);
            }
        })));
    }

    /**
     * Assemble HTTP body contents into a string.
     *
     * This method should not be used on large HTTP bodies because it loads all data into memory!
     *
     * @return string
     */
    public function getContents(): Awaitable
    {
        return new Coroutine(function () {
            return yield new ReadContents(yield $this->getReadableStream());
        });
    }

    /**
     * Discard remaining body contents.
     *
     * @return int The number of body bytes that have been discarded.
     */
    public function discard(): Awaitable
    {
        return new Success(0);
    }

    public abstract function close(bool $disconnected);

    protected abstract function nextChunk(): \Generator;
}
