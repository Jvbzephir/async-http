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
use KoolKode\Async\ReadContents;
use KoolKode\Async\Success;
use KoolKode\Async\Filesystem\Filesystem;
use KoolKode\Async\Filesystem\FilesystemProxy;
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
        $this->file = $file;
        
        $this->filesystem = new FilesystemProxy();
    }
    
    public function setFilesystem(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
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
        return $this->filesystem->size($this->file);
    }

    /**
     * {@inheritdoc}
     */
    public function getReadableStream(): Awaitable
    {
        return $this->filesystem->readStream($this->file);
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
