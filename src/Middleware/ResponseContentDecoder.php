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
 * HTTP client-side middeware that decompresses HTTP response bodies.
 * 
 * Give this middleware a very low (maybe even negative) priority to have it decompress responses before other middleware!
 * 
 * Supported content encodings are "gzip" and "deflate".
 * 
 * @author Martin Schröder
 */
class ResponseContentDecoder implements Middleware
{
    protected $zlib = \KOOLKODE_ASYNC_ZLIB;
    
    /**
     * {@inheritdoc}
     */
    public function getDefaultPriority(): int
    {
        return -100000;
    }
    
    /**
     * Handles compressed HTTP response bodies using an inflate stream.
     * 
     * The content encoding header will be removed if the middleware was able to decompress the body.
     */
    public function __invoke(Context $context, HttpRequest $request, NextMiddleware $next): \Generator
    {
        if ($this->zlib) {
            $request = $request->withAddedHeader('Accept-Encoding', 'gzip, deflate');
        }
        
        $response = yield from $next($context, $request);
        
        if ($this->zlib && $response->hasHeader('Content-Encoding')) {
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
                $stream = new InflateStream(yield $response->getBody()->getReadableStream($context), $encoding);
                
                $response = $response->withoutHeader('Content-Encoding');
                $response = $response->withBody(new StreamBody($stream));
            }
        }
        
        return $response;
    }
}
