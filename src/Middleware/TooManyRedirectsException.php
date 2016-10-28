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

namespace KoolKode\Async\Http\Middleware;

/**
 * Is thrown when the maximum number of HTTP redirects has been exceeded for a single HTTP request.
 * 
 * @author Martin Schröder
 */
class TooManyRedirectsException extends \RuntimeException { }
