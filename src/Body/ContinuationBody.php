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
use KoolKode\Async\Failure;
use KoolKode\Async\Promise;
use KoolKode\Async\Success;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\StreamClosedException;

/**
 * Request body that triggers a continuation coroutine when the body stream is accessed.
 * 
 * This body implementation is primarily used to deal with Expect: 100-continue requests on the server side.
 * 
 * @author Martin Schröder
 */
class ContinuationBody extends StreamBody
{
    /**
     * Continuation coroutine being called before the body stream is accessed.
     * 
     * @var callable
     */
    protected $continuation;

    /**
     * Create a new continuation body.
     * 
     * @param ReadableStream $stream Readable body data stream.
     * @param callable $continuation Continuation coroutine being called before the body stream is accessed.
     */
    public function __construct(ReadableStream $stream, callable $continuation)
    {
        parent::__construct($stream);
        
        $this->continuation = $continuation;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getReadableStream(Context $context): Promise
    {
        if ($this->stream === null) {
            return new Failure($context, new StreamClosedException('Body stream has already been consumed'));
        }
        
        $continuation = $this->continuation;
        $this->continuation = null;
        
        $stream = $this->stream;
        $this->stream = null;
        
        return $context->task($continuation($context, $stream));
    }

    /**
     * {@inheritdoc}
     */
    public function discard(Context $context): Promise
    {
        $this->continuation = null;
        $this->stream = null;
        
        return new Success($context, 0);
    }
}
