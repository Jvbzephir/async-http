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

use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\ReadableInflateStream;

/**
 * HTTP client-side middeware that decompresses HTTP response bodies.
 * 
 * Give this middleware a very low (maybe even negative) priority to have it decompress responses before other middleware!
 * 
 * Supported content encodings are "gzip" and "deflate".
 * 
 * @author Martin Schröder
 */
class ContentDecoder
{
    /**
     * Handles compressed HTTP response bodies using an inflate stream.
     * 
     * The content encoding header will be removed if the middleware was able to decompress the body.
     * 
     * @param HttpRequest $request
     * @param NextMiddleware $next
     * @return HttpResponse
     */
    public function __invoke(HttpRequest $request, NextMiddleware $next): \Generator
    {
        static $zlib;
        
        if ($zlib ?? ($zlib = \function_exists('inflate_init'))) {
            $request = $request->withAddedHeader('Accept-Encoding', 'gzip, deflate');
        }
        
        $response = yield from $next($request);
        
        if ($zlib && $response->hasHeader('Content-Encoding')) {
            $encoding = null;
            
            switch (\strtolower($response->getHeaderLine('Content-Encoding'))) {
                case 'gzip':
                    $encoding = \ZLIB_ENCODING_GZIP;
                    break;
                case 'deflate':
                    $encoding = \ZLIB_ENCODING_DEFLATE;
                    break;
            }
            
            if ($encoding !== null) {
                $stream = new ReadableInflateStream(yield $response->getBody()->getReadableStream(), $encoding);
                
                $response = $response->withoutHeader('Content-Encoding');
                $response = $response->withBody(new StreamBody($stream));
            }
        }
        
        return $response;
    }
}
