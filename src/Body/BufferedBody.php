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
use KoolKode\Async\Filesystem\FilesystemTempStream;
use KoolKode\Async\Http\HttpBody;

class BufferedBody implements HttpBody
{
    protected $temp;

    /**
     * Create a message body that can stream a file.
     * 
     * @param string $file
     */
    public function __construct(FilesystemTempStream $temp)
    {
        $this->temp = $temp;
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
    public function getSize(Context $context): Promise
    {
        return $this->temp->size($context);
    }

    /**
     * {@inheritdoc}
     */
    public function getReadableStream(Context $context): Promise
    {
        return $this->temp->readStream($context);
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(Context $context): Promise
    {
        return $context->task(function (Context $context) {
            $stream = yield $this->temp->readStream($context);
            $buffer = '';
            
            try {
                while (null !== ($chunk = yield $stream->read($context))) {
                    $buffer .= $chunk;
                }
            } finally {
                $stream->close();
            }
            
            return $buffer;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function discard(Context $context): Promise
    {
        return new Success($context, 0);
    }
}
