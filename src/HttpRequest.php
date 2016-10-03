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
 * Implementation of a PSR-7 HTTP request.
 * 
 * @author Martin Schröder
 */
class HttpRequest extends HttpMessage
{
    protected $method;

    protected $target;

    protected $uri;

    public function __construct($uri, string $method = Http::GET, array $headers = [], string $protocolVersion = '1.1')
    {
        parent::__construct($headers, $protocolVersion);
        
        $this->method = $this->filterMethod(($method === NULL) ? 'GET' : $method);
        $this->uri = Uri::parse($uri);
    }

    public function __debugInfo(): array
    {
        $headers = [];
        foreach ($this->getHeaders() as $k => $header) {
            foreach ($header as $v) {
                $headers[] = \sprintf('%s: %s', $k, $v);
            }
        }
        
        \sort($headers, SORT_NATURAL);
        
        return [
            'protocol' => \sprintf('HTTP/%s', $this->protocolVersion),
            'method' => $this->method,
            'uri' => (string) $this->uri,
            'target' => $this->getRequestTarget(),
            'headers' => $headers,
            'body' => $this->body,
            'attributes' => \array_keys($this->attributes)
        ];
    }

    public function getRequestTarget(): string
    {
        if (NULL !== $this->target) {
            return $this->target;
        }
        
        if ($this->uri === NULL) {
            return '/';
        }
        
        $target = $this->uri->getPath();
        $target .= $this->uri->getQuery() ? '?' . $this->uri->getQuery() : '';
        
        return empty($target) ? '/' : $target;
    }

    public function withRequestTarget(string $requestTarget): HttpRequest
    {
        $requestTarget = \trim($requestTarget);
        
        if (\preg_match("'\s'", $requestTarget)) {
            throw new \InvalidArgumentException('Request target must not contain whitespace');
        }
        
        $request = clone $this;
        $request->target = $requestTarget;
        
        return $request;
    }

    public function getMethod(): string
    {
        return $this->method ?: 'GET';
    }

    public function withMethod(string $method): HttpRequest
    {
        $request = clone $this;
        $request->method = $this->filterMethod($method);
        
        return $request;
    }

    public function getUri(): Uri
    {
        return $this->uri;
    }

    public function withUri(Uri $uri, bool $preserveHost = false): HttpRequest
    {
        $request = clone $this;
        $request->uri = $uri;
        
        $host = $uri->getHost();
        
        if ($host != '') {
            $host .= $uri->getPort() ? ':' . $uri->getPort() : '';
            
            if ($preserveHost) {
                if (empty($request->getHeader('Host'))) {
                    $request = $request->withHeader('Host', $host);
                }
            } else {
                $request = $request->withHeader('Host', $host);
            }
        }
        
        return $request;
    }

    public function getHeader(string $name): array
    {
        $n = \strtolower($name);
        
        if ($n === 'host' && empty($this->headers[$n]) && $this->uri !== NULL) {
            $host = $this->uri->getHost();
            
            if (!empty($host)) {
                return [
                    $host . ($this->uri->getPort() ? ':' . $this->uri->getPort() : '')
                ];
            }
        }
        
        return parent::getHeader($name);
    }

    public function getHeaders(): array
    {
        $headers = parent::getHeaders();
        
        if (empty($this->headers['host']) && $this->uri !== NULL && !empty($this->uri->getHost())) {
            $headers['Host'] = $this->getHeader('Host');
        }
        
        return $headers;
    }

    public function hasQueryParam(string $name): bool
    {
        return $this->uri->hasQueryParam($name);
    }

    public function getQueryParam(string $name)
    {
        if (\func_num_args() > 1) {
            return $this->uri->getQueryParams($name, \func_get_arg(1));
        }
        
        return $this->uri->getQueryParam($name);
    }

    public function getQueryParams(): array
    {
        return $this->uri->getQueryParams();
    }
    
    public function isContinueExpected(): bool
    {
        if ($this->hasHeader('Expect') && $this->protocolVersion === '1.1') {
            $expected = \array_map('strtolower', \array_map('trim', $this->getHeaderLine('Expect')));
            
            if (\in_array('100-continue', $expected)) {
                return true;
            }
        }
        
        return false;
    }

    protected function filterMethod(string $method): string
    {
        if (!\preg_match('/^[!#$%&\'*+.^_`\|~0-9a-z-]+$/i', $method)) {
            throw new \InvalidArgumentException(\sprintf('Invalid HTTP method'));
        }
        
        return $method;
    }
}
