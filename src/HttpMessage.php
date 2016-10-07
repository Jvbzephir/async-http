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
 * Implementation of a PSR-7 HTTP message base class.
 * 
 * @author Martin Schröder
 */
abstract class HttpMessage
{
    protected $protocolVersion;

    protected $headers;

    protected $body;

    protected $attributes = [];

    public function __construct(array $headers = [], string $protocolVersion = '1.1')
    {
        $this->protocolVersion = (string) $protocolVersion;
        $this->body = new StringBody();
        $this->headers = [];
        
        foreach ($headers as $k => $v) {
            $k = \trim($k);
            
            $filtered = $this->filterHeaders(\array_map(function (string $v) use ($k) {
                return [
                    $k,
                    \trim($v)
                ];
            }, [
                (string) $v
            ]));
            
            if (!empty($filtered)) {
                $this->headers[\strtolower($k)] = $filtered;
            }
        }
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): HttpMessage
    {
        $message = clone $this;
        $message->protocolVersion = (string) $version;
        
        return $message;
    }

    public function getHeaders(): array
    {
        $headers = [];
        
        foreach ($this->headers as $k => $data) {
            $headers[$k] = \array_map(function (array $header) {
                return $header[1];
            }, $data);
        }
        
        return $headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[\strtolower(\trim($name))]);
    }

    public function getHeader(string $name): array
    {
        $n = \strtolower(\trim($name));
        
        if (empty($this->headers[$n])) {
            return [];
        }
        
        return \array_map(function (array $header) {
            return $header[1];
        }, $this->headers[$n]);
    }

    public function getHeaderLine(string $name): string
    {
        return \implode(',', $this->getHeader($name));
    }
    
    public function getHeaderTokens(string $name, string $separator = ','): array
    {
        $tokens = [];
        
        foreach ($this->getHeader($name) as $v) {
            foreach (\explode($separator, \strtolower($v)) as $t) {
                $tokens[] = \trim($t);
            }
        }
        
        return $tokens;
    }

    public function withHeader(string $name, string ...$values): HttpMessage
    {
        $name = \trim($name);
        
        $filtered = $this->filterHeaders(\array_map(function (string $val) use ($name) {
            return [
                $name,
                \trim($val)
            ];
        }, $values));
        
        if ($filtered) {
            $message = clone $this;
            $message->headers[\strtolower($name)] = $filtered;
        }
        
        return $message ?? clone $this;
    }

    public function withAddedHeader(string $name, string ...$values): HttpMessage
    {
        $name = \trim($name);
        
        $filtered = $this->filterHeaders(\array_map(function (string $val) use ($name) {
            return [
                $name,
                \trim($val)
            ];
        }, $values));
        
        if ($filtered) {
            $n = \strtolower($name);
            
            $message = clone $this;
            $message->headers[$n] = \array_merge(empty($this->headers[$n]) ? [] : $this->headers[$n], $filtered);
        }
        
        return $message ?? clone $this;
    }

    public function withoutHeader(string $name): HttpMessage
    {
        $message = clone $this;
        unset($message->headers[\strtolower(\trim($name))]);
        
        return $message;
    }

    public function getBody(): HttpBody
    {
        return $this->body;
    }

    public function withBody(HttpBody $body): HttpMessage
    {
        $message = clone $this;
        $message->body = $body;
        
        return $message;
    }

    public function getAttribute(string $name, $default = null)
    {
        return \array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute(string $name, $value): HttpMessage
    {
        $message = clone $this;
        $message->attributes[$name] = $value;
        
        return $message;
    }

    public function withoutAttribute(string $name): HttpMessage
    {
        $message = clone $this;
        unset($message->attributes[$name]);
        
        return $message;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function withAttributes(array $attributes): HttpMessage
    {
        $message = clone $this;
        $message->attributes = $attributes;
        
        return $message;
    }

    protected function filterHeaders(array $headers): array
    {
        $filtered = [];
        
        foreach ($headers as $h) {
            if (false !== \strpos($h[0], "\n") || false !== \strpos($h[0], "\r")) {
                throw new \InvalidArgumentException('Header injection vector in header name detected');
            }
            
            if (false !== \strpos($h[1], "\n") || false !== \strpos($h[1], "\r")) {
                throw new \InvalidArgumentException('Header injection vector in header value detected');
            }
            
            if (\preg_match('/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/', $h[0])) {
                $filtered[] = $h;
            }
        }
        
        return $filtered;
    }
}
