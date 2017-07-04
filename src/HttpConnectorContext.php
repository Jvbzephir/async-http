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

namespace KoolKode\Async\Http;

use KoolKode\Async\Socket\SocketStream;


/**
 * Extendable context object that is used to allow for persistent connections etc.
 * 
 * @author Martin Schröder
 */
abstract class HttpConnectorContext
{
    /**
     * Is the context already connected (no new socket connection needed)?
     * 
     * @var bool
     */
    public $connected = false;
    
    /**
     * Socket being used to transmit HTTP messages.
     * 
     * @var SocketStream
     */
    public $socket;
    
    /**
     * Dispose the underlying connection attempt.
     * 
     * The connection can be re-used after it has been disposed (if the socket connection is alive).
     */
    public abstract function dispose();
}
