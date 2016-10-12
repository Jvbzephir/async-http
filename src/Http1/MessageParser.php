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

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpMessage;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Stream\ReadableStream;
use KoolKode\Async\Stream\StreamException;

/**
 * Base class for HTTP/1.x message parsers.
 * 
 * @author Martin Schröder
 */
abstract class MessageParser
{
    protected function parseHeaders(ReadableStream $stream, HttpMessage $message): \Generator
    {
        $remaining = 131072;
        
        try {
            while (null !== ($line = yield $stream->readLine($remaining))) {
                if (\trim($line) === '') {
                    break;
                }
                
                $remaining -= \strlen($line);
                $parts = \explode(':', $line, 2);
                
                if (!isset($parts[1])) {
                    throw new \RuntimeException('Malformed HTTP header received');
                }
                
                $message = $message->withAddedHeader(\trim($parts[0]), \trim($parts[1]));
            }
        } catch (StreamException $e) {
            throw new StatusException(Http::REQUEST_HEADER_FIELDS_TOO_LARGE, 'Maximum HTTP header size exceeded', $e);
        }
        
        if ($line === null) {
            throw new \RuntimeException('Premature end of HTTP headers detected');
        }
        
        return $message;
    }
}
