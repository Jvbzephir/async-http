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

namespace KoolKode\Async\Http\Middleware;

use KoolKode\Async\Context;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Stream\InflateStream;

/**
 * HTTP server-side middeware that decompresses HTTP request bodies.
 * 
 * Give this middleware a very high priority to have it decompress requets before other middleware!
 * 
 * Supported content encodings are "gzip" and "deflate".
 * 
 * @author Martin Schröder
 */
class RequestContentDecoder implements Middleware
{
    protected $zlib = \KOOLKODE_ASYNC_ZLIB;
    
    /**
     * {@inheritdoc}
     */
    public function getDefaultPriority(): int
    {
        return 100000;
    }

    /**
     * Handles compressed HTTP response bodies using an inflate stream.
     * 
     * The content encoding header will be removed if the middleware was able to decompress the body.
     */
    public function __invoke(Context $context, HttpRequest $request, NextMiddleware $next): \Generator
    {
        if ($this->zlib) {
            $encoding = null;
            
            switch (\strtolower($request->getHeaderLine('Content-Encoding'))) {
                case 'gzip':
                    $encoding = \ZLIB_ENCODING_GZIP;
                    break;
                case 'deflate':
                    $encoding = \ZLIB_ENCODING_DEFLATE;
                    break;
            }
            
            if ($encoding !== null) {
                $stream = new InflateStream(yield $request->getBody()->getReadableStream($context), $encoding);
                
                $request = $request->withoutHeader('Content-Encoding');
                $request = $request->withBody(new StreamBody($stream));
            }
        }
        
        return yield from $next($context, $request);
    }
}
