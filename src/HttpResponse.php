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
 * Implementation of a PSR-7 HTTP response.
 * 
 * @author Martin Schröder
 */
class HttpResponse extends HttpMessage
{
    protected $status;

    protected $reason;

    public function __construct(int $status = Http::OK, array $headers = [], string $protocolVersion = '1.1')
    {
        parent::__construct($headers, $protocolVersion);
        
        $this->status = $this->filterStatus($status);
        $this->reason = '';
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

    public function withStatus(int $code, string $reasonPhrase = ''): HttpResponse
    {
        $response = clone $this;
        $response->status = $this->filterStatus($code);
        $response->reason = \trim($reasonPhrase);
        
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
