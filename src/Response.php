<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

/**
 * Implementation of a PSR-7 HTTP response.
 * 
 * @author Martin Schröder
 */
class Response extends Message
{
    protected $status;

    protected $reason;

    public function __construct($status = NULL, array $headers = [])
    {
        parent::__construct($headers);
        
        $this->status = $this->filterStatus(($status === NULL) ? 200 : $status);
        $this->reason = '';
    }

    public function getStatusCode()
    {
        return $this->status;
    }

    public function withStatus($code, $reasonPhrase = '')
    {
        $response = clone $this;
        $response->status = $this->filterStatus($code);
        $response->reason = trim($reasonPhrase);
        
        return $response;
    }

    public function getReasonPhrase()
    {
        return $this->reason;
    }

    protected function filterStatus($status)
    {
        if (method_exists($status, '__toString')) {
            $status = (string) $status;
        }
        
        if (!is_scalar($status) || (!is_int($status) && !preg_match("'^[1-5][0-9]{2}$'", $status))) {
            throw new \InvalidArgumentException(sprintf('Invalid HTTP status code: %s', is_object($status) ? get_class($status) : gettype($status)));
        }
        
        $status = (int) $status;
        
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException(sprintf('HTTP status out of range: %u', $status));
        }
        
        return $status;
    }
}
