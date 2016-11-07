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

namespace KoolKode\Async\Http\Test;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Http\HttpClient;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Success;

class HttpTestClient extends HttpClient
{
    protected $socket;

    public function __construct(SocketStream $socket, HttpConnector ...$connectors)
    {
        parent::__construct(...$connectors);
        
        $this->socket = $socket;
    }

    protected function connectSocket(Uri $uri): Awaitable
    {
        return new Success($this->socket);
    }
}
