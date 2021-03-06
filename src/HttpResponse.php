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
 * Models an HTTP response.
 * 
 * @author Martin Schröder
 */
class HttpResponse extends HttpMessage
{
    protected $status;

    protected $reason = '';

    public function __construct(int $status = Http::OK, array $headers = [], ?HttpBody $body = null, string $protocolVersion = '2.0')
    {
        parent::__construct($headers, $body, $protocolVersion);
        
        $this->status = $this->filterStatus($status);
    }

    public function __debugInfo(): array
    {
        $headers = [];
        foreach ($this->getHeaders() as $k => $header) {
            foreach ($header as $v) {
                $headers[] = \sprintf('%s: %s', Http::normalizeHeaderName($k), $v);
            }
        }
        
        \sort($headers, SORT_NATURAL);
        
        return [
            'protocol' => \sprintf('HTTP/%s', $this->protocolVersion),
            'status' => $this->status,
            'reason' => $this->reason,
            'headers' => $headers,
            'body' => $this->body,
            'attributes' => \array_keys($this->attributes)
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

    public function withStatus(int $code, string $reasonPhrase = ''): self
    {
        $response = clone $this;
        $response->status = $this->filterStatus($code);
        $response->reason = \trim($reasonPhrase);
        
        return $response;
    }
    
    public function withReason(string $reason): self
    {
        $response = clone $this;
        $response->reason = \trim($reason);
        
        return $response;
    }

    protected function filterStatus(int $status): int
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException(\sprintf('HTTP status out of range: %d', $status));
        }
        
        return $status;
    }
}
