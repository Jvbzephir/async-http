<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Event\StoredEvent;

/**
 * Is triggered whenever all HTTP headers of a request or response have been received by an HTTP/2 stream.
 * 
 * @author Martin Schröder
 */
class MessageReceivedEvent extends StoredEvent
{
    /**
     * HTTP/2 stream being used.
     * 
     * @var Stream
     */
    public $stream;
    
    /**
     * Received HTTP headers (first element is name, second element is value).
     * 
     * @var array
     */
    public $headers;
    
    /**
     * HTTP/2 input stream that can be used to read the HTTP message body as stream.
     * 
     * @var Http2InputStream
     */
    public $body;
    
    /**
     * Microtime (as float) of the incoming HTTP request.
     * 
     * @var float
     */
    public $started;
    
    /**
     * HTTP message has been received via HTTP/2.
     * 
     * @param Stream $stream HTTP/2 stream.
     * @param array $headers Received HTTP headers.
     * @param Http2InputStream $body Message body stream.
     * @param float $started Microtime (as float) of the incoming HTTP request.
     */
    public function __construct(Stream $stream, array $headers, Http2InputStream $body, float $started = NULL)
    {
        $this->stream = $stream;
        $this->headers = $headers;
        $this->body = $body;
        $this->started = $started ?? microtime(true);
    }
    
    /**
     * Get the first value of the given HTTP header.
     * 
     * @param string $name Name of the HTTP header.
     * @return string First value or an empty string.
     */
    public function getHeaderValue(string $name): string
    {
        foreach ($this->headers as $header) {
            if ($header[0] === $name) {
                return $header[1];
            }
        }
        
        return '';
    }
}
