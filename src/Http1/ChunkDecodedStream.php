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
use KoolKode\Async\Stream\ReadableStreamDecorator;
use KoolKode\Async\Stream\StreamException;

/**
 * Stream that transparently applies HTTP chunk decoding.
 * 
 * @author Martin Schröder
 */
class ChunkDecodedStream extends ReadableStreamDecorator
{
    /**
     * Number of remaining bytes to be read from the current chunk.
     * 
     * @var int
     */
    protected $remainder;

    /**
     * {@inheritdoc}
     */
    protected function readNextChunk(Context $context): \Generator
    {
        if ($this->remainder === 0) {
            if ("\r\n" !== yield $this->stream->readBuffer($context, 2, false)) {
                throw new StreamException('Missing CRLF after chunk');
            }
        }
        
        if (empty($this->remainder)) {
            if (null === ($header = yield $this->stream->readLine($context))) {
                return;
            }
            
            $header = \trim(\preg_replace("';.*$'", '', $header));
            
            if (!\ctype_xdigit($header) || \strlen($header) > 7) {
                throw new StreamException(\sprintf('Invalid HTTP chunk length received: "%s"', $header));
            }
            
            $this->remainder = \hexdec($header);
            
            if ($this->remainder === 0) {
                if ("\r\n" !== yield $this->stream->readBuffer($context, 2, false)) {
                    throw new StreamException('Missing CRLF after last chunk');
                }
                
                return;
            }
        }
        
        return yield $this->stream->read($context, \min(8192, $this->remainder));
    }

    /**
     * {@inheritdoc}
     */
    protected function processChunk(string $chunk): string
    {
        $this->remainder -= \strlen($chunk);
        
        return $chunk;
    }
}
