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
use KoolKode\Async\Channel\InputChannel;
use KoolKode\Async\Stream\AbstractReadableStream;

/**
 * Decompresses contents of a binary WebSocket message during reads.
 *
 * @author Martin Schröder
 */
class BinaryStream extends AbstractReadableStream
{
    protected $channel;
    
    public function __construct(InputChannel $channel, string $buffer)
    {
        $this->channel = $channel;
        $this->buffer = $buffer;
    }

    /**
     * {@inheritdoc}
     */
    public function close(?\Throwable $e = null): void
    {
        parent::close($e);
        
        $this->channel->close($e);
    }
    
    /**
     * {@inheritdoc}
     */
    protected function readNextChunk(Context $context): \Generator
    {
        return yield $this->channel->receive($context);
    }
}
