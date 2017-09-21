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
use KoolKode\Async\Concurrent\Filesystem\PoolFilesystem;
use KoolKode\Async\Concurrent\Pool\SyncPool;
use KoolKode\Async\Http\HttpBody;

/**
 * HTTP body that can stream contents of a file.
 * 
 * @author Martin Schröder
 */
class FileBody implements HttpBody
{
    /**
     * Path the file being transfered.
     * 
     * @var string
     */
    protected $file;
    
    protected $filesystem;

    /**
     * Create a message body that can stream a file.
     * 
     * @param string $file
     */
    public function __construct(string $file)
    {
        // FIXME: Re-implement filesystem proxy!
        
        $this->file = $file;
        $this->filesystem = new PoolFilesystem(new SyncPool());
    }

    /**
     * {@inheritdoc}
     */
    public function isCached(): bool
    {
        return true;
    }

    /**
     * Get the path of the transfered file.
     * 
     * @return string
     */
    public function getFile(): string
    {
        return $this->file;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize(Context $context): Promise
    {
        return $this->filesystem->size($context, $this->file);
    }

    /**
     * {@inheritdoc}
     */
    public function getReadableStream(Context $context): Promise
    {
        return $this->filesystem->readStream($context, $this->file);
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(Context $context): Promise
    {
        return $context->task(function (Context $context) {
            $stream = yield $this->filesystem->readStream($context, $this->file);
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
