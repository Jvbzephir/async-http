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

use KoolKode\Async\DNS\Address;
use KoolKode\Async\Http\Header\Accept;
use KoolKode\Async\Http\Header\ContentType;

/**
 * Models an HTTP request.
 * 
 * @author Martin Schröder
 */
class HttpRequest extends HttpMessage
{
    protected $method;

    protected $target;

    protected $uri;
    
    protected $addresses = [];

    public function __construct($uri, string $method = Http::GET, array $headers = [], ?HttpBody $body = null, string $protocolVersion = '2.0')
    {
        parent::__construct($headers, $body, $protocolVersion);
        
        $this->method = $this->filterMethod($method);
        $this->uri = Uri::parse($uri);
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
            'method' => $this->method,
            'uri' => (string) $this->uri,
            'target' => $this->getRequestTarget(),
            'headers' => $headers,
            'addresses' => $this->addresses,
            'body' => $this->body,
            'attributes' => \array_keys($this->attributes)
        ];
    }

    public function getRequestTarget(): string
    {
        if (null !== $this->target) {
            return $this->target;
        }
        
        $target = $this->uri->getPath() . ($this->uri->getQuery() ? ('?' . $this->uri->getQuery()) : '');
        
        return empty($target) ? '/' : $target;
    }

    public function withRequestTarget(string $requestTarget): self
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
        return $this->method;
    }

    public function withMethod(string $method): self
    {
        $request = clone $this;
        $request->method = $this->filterMethod($method);
        
        return $request;
    }

    public function getUri(): Uri
    {
        return $this->uri;
    }

    public function withUri($uri): self
    {
        $uri = Uri::parse($uri);
        
        $request = clone $this;
        $request->uri = $uri;
        
        $host = $uri->getHost();
        
        if ($host != '') {
            $host .= $uri->getPort() ? ':' . $uri->getPort() : '';
            $request = $request->withHeader('Host', $host);
        }
        
        return $request;
    }
    
    public function getAccept(): Accept
    {
        return new Accept(...ContentType::parseList($this->getHeaderLine('Accept')));
    }
    
    public function hasHeader(string $name): bool
    {
        $name = \strtolower($name);
        
        if ($name === 'host') {
            return $this->getHeader('Host') ? true : false;
        }
        
        return isset($this->headers[$name]);
    }

    public function getHeader(string $name): array
    {
        $n = \strtolower($name);
        
        if ($n === 'host' && empty($this->headers[$n]) && $this->uri !== null) {
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
        
        if (empty($this->headers['host']) && $this->uri !== null && !empty($this->uri->getHost())) {
            $headers['host'] = $this->getHeader('Host');
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
            return $this->uri->getQueryParam($name, \func_get_arg(1));
        }
        
        return $this->uri->getQueryParam($name);
    }

    public function getQueryParams(): array
    {
        return $this->uri->getQueryParams();
    }

    public function getClientAddress(): string
    {
        return isset($this->addresses[0]) ? $this->addresses[0] : '';
    }

    public function getProxyAddresses(): array
    {
        return \array_slice($this->addresses, 1);
    }

    public function withAddress(string ...$address): self
    {
        $request = clone $this;
        
        foreach ($address as $ip) {
            $request->addresses[] = (string) new Address($ip);
        }
        
        return $request;
    }

    protected function filterMethod(string $method): string
    {
        if (!\preg_match('/^[!#$%&\'*+.^_`\|~0-9a-z-]+$/i', $method)) {
            throw new \InvalidArgumentException(\sprintf('Invalid HTTP method'));
        }
        
        return $method;
    }
}
