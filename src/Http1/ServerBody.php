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

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Context;
use KoolKode\Async\Failure;
use KoolKode\Async\Promise;
use KoolKode\Async\Success;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Stream\WritableStream;

class ServerBody extends StreamBody
{
    protected $expect;

    public function __construct(ReadableStream $stream, WritableStream $expect)
    {
        parent::__construct($stream);
        
        $this->expect = $expect;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getReadableStream(Context $context): Promise
    {
        if ($this->stream === null) {
            return new Failure($context, new StreamClosedException());
        }
        
        if ($this->expect === null) {
            return new Success($context, $this->stream);
        }
        
        $expect = $this->expect;
        $this->expect = null;
        
        $stream = $this->stream;
        $this->stream = null;
        
        return $context->transform($expect->write($context, Http::getStatusLine(Http::CONTINUE) . "\r\n\r\n"), function () use ($stream) {
            return $stream;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function discard(Context $context): Promise
    {
        if ($this->expect || $this->stream === null) {
            return new Success($context);
        }
        
        return parent::discard($context);
    }
}
