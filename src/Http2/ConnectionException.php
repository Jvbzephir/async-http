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

use KoolKode\Async\Stream\StreamException;

/**
 * A connection error is any error that prevents further processing of the frame layer or corrupts any connection state.
 * 
 * @author Martin Schröder
 */
class ConnectionException extends StreamException { }
