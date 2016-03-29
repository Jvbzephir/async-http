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

use KoolKode\Async\Socket\SocketStream;

/**
 * Context being used to pass additional values into an HTTP connector.
 * 
 * @author Martin Schröder
 */
class HttpConnectorContext
{
    /**
     * Socket stream to be used as transport.
     * 
     * @var SocketStream
     */
    public $socket;
    
    /**
     * PHP stream context options to be used (only applicable when no socket is set).
     * 
     * @var array
     */
    public $options = [];
    
    /**
     * Passing a socket stream will have the connector use the socket instead of creating a new socket connection.
     * 
     * @param SocketStream $socket
     */
    public function __construct(SocketStream $socket = NULL)
    {
        $this->socket = $socket;
    }
}
