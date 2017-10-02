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

namespace KoolKode\Async\Http\WebSocket;

use KoolKode\Async\Context;
use KoolKode\Async\Concurrent\Synchronizer;
use KoolKode\Async\Stream\AbstractReadableStream;

/**
 * Decompresses contents of a binary WebSocket message during reads.
 *
 * @author Martin Schröder
 */
class BinaryStream extends AbstractReadableStream
{
    protected $sync;
    
    public function __construct(Synchronizer $sync, string $buffer)
    {
        $this->sync = $sync;
        $this->buffer = $buffer;
    }

    /**
     * {@inheritdoc}
     */
    public function close(?\Throwable $e = null): void
    {
        parent::close($e);
        
        $this->sync->close($e);
    }
    
    /**
     * {@inheritdoc}
     */
    protected function readNextChunk(Context $context): \Generator
    {
        return yield $this->sync->receive($context);
    }
}
