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

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StreamBody;
use KoolKode\Async\Stream\ReadableDeflateStream;
use KoolKode\Util\InvalidMediaTypeException;
use KoolKode\Util\MediaType;

/**
 * Middleware that compresses HTTP response bodies.
 * 
 * Compression will be enabled based on the content type of the response.
 * 
 * Supported content encodings are "gzip" and "deflate".
 * 
 * @author Martin Schröder
 */
class ContentEncoder
{
    /**
     * Compressable content types.
     * 
     * @var array
     */
    protected $types = [
        'application/vnd.ms-fontobject',
        'font/eot',
        'font/opentype',
        'image/bmp',
        'image/vnd.microsoft.icon',
        'image/x-icon',
        'text/cache-manifest',
        'text/plain',
        'text/vcard',
        'text/vtt',
        'text/x-component',
        'text/x-cross-domain-policy'
    ];

    /**
     * Compressable media sub types.
     * 
     * @var array
     */
    protected $subTypes = [
        'css' => true,
        'javascript' => true,
        'json' => true,
        'html' => true,
        'xhtml' => true,
        'xml' => true,
        'x-font-ttf' => true,
        'x-javascript'
    ];

    /**
     * Add a media type that should be served compressed.
     * 
     * @param string $type
     */
    public function addType(string $type)
    {
        $this->types = $type;
    }

    /**
     * Add a media sub type that should be served compressed.
     * 
     * @param string $type
     */
    public function addSubType(string $type)
    {
        $this->subTypes[$type] = true;
    }

    /**
     * Compresses the response body using a deflate stream if compression is supported by the client (accept encoding header).
     * 
     * Will set a content encoding header if the body has been compressed.
     * 
     * @param HttpRequest $request
     * @param NextMiddleware $next
     * @return HttpResponse
     */
    public function __invoke(HttpRequest $request, NextMiddleware $next): \Generator
    {
        $response = yield from $next($request);
        
        if ($this->isCompressable($request, $response)) {
            $compress = null;
            
            foreach ($request->getHeaderTokens('Accept-Encoding') as $encoding) {
                switch ($encoding) {
                    case 'gzip':
                        $compress = \ZLIB_ENCODING_GZIP;
                        break;
                    case 'deflate':
                        $compress = \ZLIB_ENCODING_DEFLATE;
                        break;
                }
                
                if ($compress === null) {
                    continue;
                }
                
                $stream = new ReadableDeflateStream(yield $response->getBody()->getReadableStream(), $compress);
                
                $response = $response->withHeader('Content-Encoding', $encoding);
                $response = $response->withBody(new StreamBody($stream));
                
                break;
            }
        }
        
        return $response;
    }

    protected function isCompressable(HttpRequest $request, HttpResponse $response): bool
    {
        if ($request->getMethod() === Http::HEAD) {
            return false;
        }
        
        if (Http::isResponseWithoutBody($response->getStatusCode())) {
            return false;
        }
        
        if ($response->hasHeader('Content-Encoding')) {
            return false;
        }
        
        try {
            $media = new MediaType($response->getHeaderLine('Content-Type'));
        } catch (InvalidMediaTypeException $e) {
            return false;
        }
        
        foreach ($media->getSubTypes() as $sub) {
            if (isset($this->subTypes[$sub])) {
                return true;
            }
        }
        
        foreach ($this->types as $type) {
            if ($media->is($type)) {
                return true;
            }
        }
        
        return false;
    }
}
