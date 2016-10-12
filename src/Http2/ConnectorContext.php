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

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Http\HttpConnectorContext;

/**
 * Extended connector context that allows reuse of an established HTTP/2 connection.
 * 
 * @author Martin Schröder
 */
class ConnectorContext extends HttpConnectorContext
{
    /**
     * HTTP/2 connection.
     * 
     * @var Connection
     */
    public $conn;
}
