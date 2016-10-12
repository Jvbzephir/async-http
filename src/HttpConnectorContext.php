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

use KoolKode\Async\Stream\DuplexStream;

/**
 * Extendable context object that is used to allow for persistent connections etc.
 * 
 * @author Martin Schröder
 */
class HttpConnectorContext
{
    /**
     * Is the context already connected (no new socket connection needed)?
     * 
     * @var bool
     */
    public $connected = false;
    
    /**
     * Stream resource to be used to send an HTTP request.
     * 
     * @var DuplexStream
     */
    public $stream;
}
