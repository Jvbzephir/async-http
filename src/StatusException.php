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

/**
 * Triggers an HTTP error status response.
 * 
 * @author Martin Schröder
 */
class StatusException extends \RuntimeException 
{
    /**
     * Trigger an HTTP error status response.
     * 
     * @param int $status
     * @param \Throwable $cause
     */
    public function __construct(int $status, \Throwable $cause = NULL)
    {
        parent::__construct(Http::getReason($status, \sprintf('HTTP status %s', $status)), $status, $cause);
    }
}
