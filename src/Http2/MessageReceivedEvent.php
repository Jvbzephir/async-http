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

class MessageReceivedEvent
{
    public $stream;
    
    public $headers;
    
    public $body;
    
    public function __construct(Stream $stream, array $headers, Http2InputStream $body)
    {
        $this->stream = $stream;
        $this->headers = $headers;
        $this->body = $body;
    }

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
