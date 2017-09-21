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

namespace KoolKode\Async\Http\Response;

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Http\Header\ContentType;

/**
 * HTTP response that transfers a text-based payload.
 * 
 * @author Martin Schröder
 */
class TextResponse extends HttpResponse
{
    /**
     * Create a new HTTP text response.
     * 
     * @param string $contents Text to be transfered as response body.
     * @param string $type media type of the text payload.
     * @param string $charset Charset to be used (default to UTF-8)..
     */
    public function __construct(string $contents, string $type = 'text/plain', ?string $charset = null)
    {
        $type = new ContentType($type);
        
        if ($type->getMediaType()->isText()) {
            $type->setParam('charset', $charset ?? 'utf-8');
        }
        
        parent::__construct(Http::OK, [
            'Content-Type' => (string) $type
        ]);
        
        $this->body = new StringBody($contents);
    }
}
