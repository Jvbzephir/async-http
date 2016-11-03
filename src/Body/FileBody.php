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
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\HttpBody;
use KoolKode\Async\Loop\LoopConfig;
use KoolKode\Async\ReadContents;
use KoolKode\Async\Success;

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

    /**
     * Create a message body that can stream a file.
     * 
     * @param string $file
     */
    public function __construct(string $file)
    {
        $this->file = $file;
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
    public function getSize(): Awaitable
    {
        return LoopConfig::currentFilesystem()->size($this->file);
    }

    /**
     * {@inheritdoc}
     */
    public function getReadableStream(): Awaitable
    {
        return LoopConfig::currentFilesystem()->readStream($this->file);
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(): Awaitable
    {
        return new Coroutine(function () {
            return yield new ReadContents(yield $this->getReadableStream());
        });
    }
    
    /**
     * {@inheritdoc}
     */
    public function discard(): Awaitable
    {
        return new Success(0);
    }
}
