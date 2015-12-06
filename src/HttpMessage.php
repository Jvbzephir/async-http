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
 * Implementation of a PSR-7 HTTP message base class.
 * 
 * @author Martin Schröder
 */
abstract class HttpMessage
{
    protected $protocolVersion;

    protected $headers;
    
    protected $body;

    public function __construct(array $headers = [], string $protocolVersion = '1.1')
    {
        $this->protocolVersion = (string) $protocolVersion;
        $this->headers = [];
        
        $this->body = new HttpBody(new \ArrayIterator([]));
        
        foreach ($headers as $k => $v) {
            if (is_object($v)) {
                if (!method_exists($v, '__toString')) {
                    continue;
                }
                
                $v = (string) $v;
            }
            
            $filtered = $this->filterHeaders(array_map(function ($v) use ($k) {
                return [
                    $k,
                    $v
                ];
            }, (array) $v));
            
            if (!empty($filtered)) {
                $this->headers[strtolower($k)] = $filtered;
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
        
        foreach ($this->headers as $data) {
            $headers[$data[0][0]] = array_map(function (array $header) {
                return $header[1];
            }, $data);
        }
        
        return $headers;
    }

    public function hasHeader(string $name): bool
    {
        return !empty($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $n = strtolower($name);
        
        if (empty($this->headers[$n])) {
            return [];
        }
        
        return array_map(function (array $header) {
            return $header[1];
        }, $this->headers[$n]);
    }

    public function getHeaderLine(string $name): string
    {
        return implode(',', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): HttpMessage
    {
        if (is_string($value) || method_exists($value, '__toString')) {
            $value = [
                (string) $value
            ];
        }
        
        if (!is_array($value) || !$this->assertArrayofStrings($value)) {
            throw new \InvalidArgumentException('Invalid HTTP header value');
        }
        
        $filtered = $this->filterHeaders(array_map(function ($val) use ($name) {
            return [
                $name,
                (string) $val
            ];
        }, $value));
        
        if (empty($filtered)) {
            return $this;
        }
        
        $message = clone $this;
        $message->headers[strtolower($name)] = $filtered;
        
        return $message;
    }

    public function withAddedHeader(string $name, $value): HttpMessage
    {
        if (is_string($value) || method_exists($value, '__toString')) {
            $value = [
                (string) $value
            ];
        }
        
        if (!is_array($value) || !$this->assertArrayofStrings($value)) {
            throw new \InvalidArgumentException('Invalid HTTP header value');
        }
        
        $filtered = $this->filterHeaders(array_map(function ($val) use ($name) {
            return [
                $name,
                (string) $val
            ];
        }, $value));
        
        if (empty($filtered)) {
            return $this;
        }
        
        $n = strtolower($name);
        
        $message = clone $this;
        $message->headers[$n] = array_merge(empty($this->headers[$n]) ? [] : $this->headers[$n], $filtered);
        
        return $message;
    }

    public function withoutHeader(string $name): HttpMessage
    {
        $message = clone $this;
        unset($message->headers[strtolower($name)]);
        
        return $message;
    }
    
    public function getBody(): HttpBody
    {
        return $this->body;
    }
    
    public function withBody($body): HttpMessage
    {
        $body = new HttpBody($body);
        
        $message = clone $this;
        $message->body = $body;
        
        return $message;
    }

    protected function assertArrayOfStrings(array $strings): bool
    {
        foreach ($strings as $string) {
            if (!is_string($string) && !method_exists($string, '__toString')) {
                return false;
            }
        }
        
        return true;
    }

    protected function filterHeaders(array $headers): array
    {
        $filtered = [];
        
        foreach ($headers as $h) {
            if (!is_string($h[0]) || !is_string($h[1])) {
                continue;
            }
            
            if (false !== strpos($h[0], "\n") || false !== strpos($h[0], "\r") || false !== strpos($h[1], "\n") || false !== strpos($h[1], "\r")) {
                throw new \InvalidArgumentException('Header injection vector detected');
            }
            
            if (!preg_match('/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/', $h[0])) {
                continue;
            }
            
            $filtered[] = $h;
        }
        
        return $filtered;
    }
}
