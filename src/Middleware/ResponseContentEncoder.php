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
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Stream\DeflateStream;
use KoolKode\Util\InvalidMediaTypeException;

/**
 * HTTP server-side middleware that compresses HTTP response bodies.
 * 
 * Give this middleware a very high priority to have it compress HTTP responses generated by other middleware!
 * 
 * Compression will be enabled based on the content type of the response.
 * 
 * Supported content encodings are "gzip" and "deflate".
 * 
 * @author Martin Schröder
 */
class ResponseContentEncoder implements Middleware
{
    protected $zlib = \KOOLKODE_ASYNC_ZLIB;
    
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
     * {@inheritdoc}
     */
    public function getDefaultPriority(): int
    {
        return 100000;
    }

    /**
     * Compresses the response body using a deflate stream if compression is supported by the client (accept encoding header).
     * 
     * Will set a content encoding header if the body has been compressed.
     */
    public function __invoke(Context $context, HttpRequest $request, NextMiddleware $next): \Generator
    {
        $response = yield from $next($context, $request);
        
        if ($this->zlib && $this->isCompressable($request, $response)) {
            $compress = null;
            
            foreach ($request->getHeaderTokenValues('Accept-Encoding') as $encoding) {
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
                
                $stream = new DeflateStream(yield $response->getBody()->getReadableStream($context), $compress);
                
                $response = $response->withHeader('Content-Encoding', $encoding);
                $response = $response->withBody(new StreamBody($stream));
                
                break;
            }
        }
        
        return $response->withAddedHeader('Vary', 'Accept-Encoding');
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
        
        try {
            $media = $response->getContentType()->getMediaType();
        } catch (InvalidMediaTypeException $e) {
            return false;
        }
        
        if (isset($this->types[(string) $media])) {
            return true;
        }
        
        foreach ($media->getSubTypes() as $sub) {
            if (isset($this->subTypes[$sub])) {
                return true;
            }
        }
        
        return false;
    }
}
