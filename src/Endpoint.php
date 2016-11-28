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

use KoolKode\Async\Awaitable;

/**
 * Minimal contract for HTTP endpoint implementations.
 * 
 * @author Martin Schröder
 */
interface Endpoint
{
    /**
     * Start processing incoming HTTP requests.
     * 
     * @param callable $action
     */
    public function listen(callable $action): Awaitable;
}
