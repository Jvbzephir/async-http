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
     * Addition HTTP response headers.
     * 
     * @var array
     */
    protected $headers = [];

    /**
     * Trigger an HTTP error status response.
     * 
     * @param int $status
     * @param string $reason
     * @param array $headers
     * @param \Throwable $cause
     */
    public function __construct(int $status, ?string $reason = null, array $headers = [], ?\Throwable $cause = null)
    {
        parent::__construct($reason ?? Http::getReason($status, \sprintf('HTTP status %s', $status)), $status, $cause);
        
        foreach ($headers as $k => $vals) {
            $this->headers[\strtolower($k)] = (array) $vals;
        }
    }

    /**
     * Get all HTTP headers to be sent (keys are header names, each entry is an array containing header values).
     * 
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
