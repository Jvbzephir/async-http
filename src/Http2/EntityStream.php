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

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Context;
use KoolKode\Async\Placeholder;
use KoolKode\Async\Stream\AbstractReadableStream;

class EntityStream extends AbstractReadableStream
{
    protected $connection;
    
    protected $id;
    
    protected $placeholder;
    
    public function __construct(Connection $connection, int $id)
    {
        $this->connection = $connection;
        $this->id = $id;
    }

    public function appendData(string $data)
    {
        if ($this->closed) {
            $this->connection->windowUpdate(\strlen($data), $this->id);
        } else {
            if ($this->placeholder) {
                $this->placeholder->resolve($data);
            } else {
                $this->buffer .= $data;
            }
        }
    }

    public function finish(): void
    {
        $this->done = true;
        
        if ($this->placeholder) {
            $this->placeholder->resolve();
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function readNextChunk(Context $context): \Generator
    {
        $this->placeholder = new Placeholder($context);
        
        try {
            $chunk = yield $context->keepBusy($this->placeholder->promise());
        } finally {
            $this->placeholder = null;
        }
        
        if ($chunk !== null) {
            $this->connection->windowUpdate(\strlen($chunk), $this->id);
        }
        
        return $chunk;
    }
}
