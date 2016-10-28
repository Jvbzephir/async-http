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
        'application/vnd.ms-fontobject' => true,
        'font/eot' => true,
        'font/opentype' => true,
        'image/bmp' => true,
        'image/vnd.microsoft.icon' => true,
        'image/x-icon' => true,
        'text/cache-manifest' => true,
        'text/plain' => true,
        'text/vcard' => true,
        'text/vtt' => true,
        'text/x-component' => true,
        'text/x-cross-domain-policy' => true
    ];

    /**
     * Compressable media sub types.
     * 
     * @var array
     */
    protected $subTypes = [
        'css' => true,
        'ecmascript' => true,
        'javascript' => true,
        'json' => true,
        'html' => true,
        'xhtml' => true,
        'xml' => true,
        'x-ecmascript' => true,
        'x-font-ttf' => true,
        'x-javascript' => true
    ];
    
    /**
     * Create a new content encoder.
     * 
     * @param array $types Compressable media types (passing null will use a default list).
     * @param array $subTypes Compressable media sub types (passing null will use a default list).
     */
    public function __construct(array $types = null, array $subTypes = null)
    {
        if ($types !== null) {
            foreach ($types as $type) {
                $this->types[(string) $type] = true;
            }
        }
        
        if ($subTypes !== null) {
            foreach ($subTypes as $type) {
                $this->subTypes[(string) $type] = true;
            }
        }
    }

    /**
     * Add a media type that should be served compressed.
     * 
     * @param string $type
     */
    public function addType(string $type)
    {
        $this->types[$type] = true;
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
        static $zlib;
        
        $response = yield from $next($request);
        
        if (($zlib ?? ($zlib = \function_exists('deflate_init'))) && $this->isCompressable($request, $response)) {
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

    /**
     * Check if the given response payload should be compressed.
     * 
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @return bool
     */
    protected function isCompressable(HttpRequest $request, HttpResponse $response): bool
    {
        if ($request->getMethod() === Http::HEAD) {
            return false;
        }
        
        if (Http::isResponseWithoutBody($response->getStatusCode())) {
            return false;
        }
        
        if ($response->hasHeader('Content-Encoding') || !$response->hasHeader('Content-Type')) {
            return false;
        }
        
        $type = \preg_replace("';.*$'", '', $response->getHeaderLine('Content-Type'));
        
        if (isset($this->types[$type])) {
            return true;
        }
        
        try {
            $media = new MediaType($type);
        } catch (InvalidMediaTypeException $e) {
            return false;
        }
        
        foreach ($media->getSubTypes() as $sub) {
            if (isset($this->subTypes[$sub])) {
                return true;
            }
        }
        
        return false;
    }
}
