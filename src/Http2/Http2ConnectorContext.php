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

class Http2ConnectorContext extends HttpConnectorContext
{
    public $conn;
    
    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }
}
