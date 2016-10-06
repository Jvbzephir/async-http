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

use KoolKode\Async\Http\HttpMessage;
use KoolKode\Async\Stream\ReadableStream;

abstract class MessageParser
{
    protected function parseHeaders(ReadableStream $stream, HttpMessage $message): \Generator
    {
        while (NULL !== ($line = yield $stream->readLine())) {
            if (\trim($line) === '') {
                break;
            }
            
            $parts = \explode(':', $line, 2);
            
            $message = $message->withAddedHeader(\trim($parts[0]), \trim($parts[1]));
        }
        
        return $message;
    }
}
