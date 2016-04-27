<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

use KoolKode\Async\Stream\Stream;

use function KoolKode\Async\currentExecutor;
use function KoolKode\Async\fileOpenRead;

class FileBody implements HttpBodyInterface
{
    protected $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function getFile(): string
    {
        return $this->file;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getSize(): \Generator
    {
        return yield from (yield currentExecutor())->getFilesystem()->size($this->file);
    }

    /**
     * {@inheritdoc}
     */
    public function getInputStream(): \Generator
    {
        return yield fileOpenRead($this->file);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getContents(): \Generator
    {
        return yield from Stream::readContents(yield from $this->getInputStream());
    }
}
