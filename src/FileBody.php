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

/**
 * HTTP body that can stream contents of a file.
 * 
 * @author Martin Schröder
 */
class FileBody implements HttpBodyInterface
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
