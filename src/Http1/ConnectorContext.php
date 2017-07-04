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

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Http\HttpConnectorContext;

/**
 * Extended connector context that allows reuse of an established HTTP/1 connection.
 * 
 * @author Martin Schröder
 */
class ConnectorContext extends HttpConnectorContext
{
    /**
     * Remaining number of requests that the server is willing to accept for the connection.
     * 
     * @var int
     */
    public $remaining;

    /**
     * Expiry date of the connection.
     * 
     * @var int
     */
    public $expires;

    /**
     * Dispose callback.
     * 
     * @var callable
     */
    protected $disposer;

    /**
     * Create a new HTTP/1 connector context.
     * 
     * @param callable $disposer
     */
    public function __construct(callable $disposer)
    {
        $this->disposer = $disposer;
    }

    /**
     * {@inheritdoc}
     */
    public function dispose()
    {
        ($this->disposer)($this);
    }
}
