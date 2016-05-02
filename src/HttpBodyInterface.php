<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

use KoolKode\Async\Stream\InputStreamInterface;

/**
 * Contract for an HTTP message body.
 * 
 * @author Martin Schröder
 */
interface HttpBodyInterface
{
    /**
     * Check if the contents / input stream can be accessed multiple times (replayed).
     * 
     * This is needed when an HTTP client transmits the body and the server answers with a redirect
     * that requires another HTTP request with the same body.
     * 
     * @return bool
     */
    public function isCached(): bool;
    
    /**
     * Prepare HTTP message for the body payload (add / remove HTTP headers etc.).
     * 
     * @param HttpMessage $message
     * @return HttpMessage
     */
    public function prepareMessage(HttpMessage $message): HttpMessage;
    
    /**
     * Get the size of the request body.
     * 
     * This method must return NULL when body size is unknown!
     * 
     * @return int Body size in bytes or NULL when size is unknown.
     */
    public function getSize(): \Generator;
    
    /**
     * Provides an input stream that can be used to read HTTP body contents.
     * 
     * @return InputStreamInterface
     */
    public function getInputStream(): \Generator;
    
    /**
     * Assemble HTTP body contents into a string.
     * 
     * This method should not be used on large HTTP bodies because it loads all data into memory!
     * 
     * @return string
     */
    public function getContents(): \Generator;
}
