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

use KoolKode\Async\Http\HttpConnectorContext;

/**
 * Connector context being used by HTTP/2 to associate an HTTP request with an established connection.
 * 
 * @author Martin Schröder
 */
class Http2ConnectorContext extends HttpConnectorContext
{
    /**
     * HTTP/2 connection to be used.
     * 
     * @var Connection
     */
    public $conn;
    
    /**
     * Associate an HTTP request with an HTTP/2 connection.
     * 
     * @param Connection $conn
     */
    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }
}
