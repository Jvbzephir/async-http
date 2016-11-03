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

namespace KoolKode\Async\Http\WebSocket;

/**
 * Exception that will close a WebSocket connection if not handled within application code.
 * 
 * @author Martin Schröder
 */
class ConnectionException extends \RuntimeException { }
