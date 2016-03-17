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

use KoolKode\Async\Stream\InputStreamInterface;
use KoolKode\Async\Stream\StringInputStream;

/**
 * Implementation of a PSR-7 HTTP response.
 * 
 * @author Martin Schröder
 */
class HttpResponse extends HttpMessage
{
    protected $status;

    protected $reason;

    public function __construct(int $status = Http::CODE_OK, InputStreamInterface $body = NULL, array $headers = [])
    {
        parent::__construct($body ?? new StringInputStream(), $headers);
        
        $this->status = $this->filterStatus(($status === NULL) ? 200 : $status);
        $this->reason = '';
    }
    
    public function __debugInfo(): array
    {
        $headers = [];
        foreach ($this->getHeaders() as $k => $header) {
            foreach ($header as $v) {
                $headers[] = sprintf('%s: %s', $k, $v);
            }
        }
        
        sort($headers, SORT_NATURAL);
        
        return [
            'protocol' => sprintf('HTTP/%s', $this->protocolVersion),
            'status' => $this->status,
            'reason' => $this->reason,
            'headers' => $headers,
            'body' => $this->body,
            'attributes' => array_keys($this->attributes)
        ];
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getReasonPhrase(): string
    {
        return $this->reason;
    }
    
    public function withStatus(int $code, string $reasonPhrase = ''): HttpResponse
    {
        $response = clone $this;
        $response->status = $this->filterStatus($code);
        $response->reason = trim($reasonPhrase);
        
        return $response;
    }
    
    protected function filterStatus(int $status): int
    {
        if (method_exists($status, '__toString')) {
            $status = (string) $status;
        }
        
        if (!preg_match("'^[1-5][0-9]{2}$'", $status)) {
            throw new \InvalidArgumentException(sprintf('Invalid HTTP status code: %s', is_object($status) ? get_class($status) : gettype($status)));
        }
        
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException(sprintf('HTTP status out of range: %u', $status));
        }
        
        return $status;
    }
}
