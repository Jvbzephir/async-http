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

use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\NextMiddleware;
use KoolKode\Async\Http\StreamBody;
use KoolKode\Async\Stream\ReadableInflateStream;

class ContentDecoder
{
    public function __invoke(HttpRequest $request, NextMiddleware $next): \Generator
    {
        static $zlib;
        
        if ($zlib === null) {
            $zlib = \function_exists('inflate_init');
        }
        
        if ($zlib) {
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
