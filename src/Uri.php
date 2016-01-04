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
 * Imutable PSR-7 HTTP URI implementation, calling mutators (setters) will create and return a new URI object with the changes being applied.
 * 
 * @author Martin Schröder
 */
class Uri implements \JsonSerializable
{
    protected $scheme;

    protected $host;

    protected $port;

    protected $username;

    protected $password;

    protected $path;

    protected $query;

    protected $parsedQuery;

    protected $fragment;

    protected $uriString;

    public function __construct(string $scheme = 'http', string $host = '', int $port = NULL, string $path = '', string $query = '', string $fragment = '', string $username = '', string $password = '')
    {
        $this->scheme = $this->filterScheme($scheme);
        $this->host = (string) $host;
        $this->port = ($port === NULL) ? NULL : (int) $port;
        $this->path = $this->filterPath($path);
        $this->query = $this->filterQuery($query);
        $this->fragment = $this->filterFragment($fragment);
        $this->username = (string) $username;
        $this->password = (string) $password;
    }

    public function __clone()
    {
        $this->uriString = NULL;
    }

    /**
     * Parse a URI from a string value.
     * 
     * @param string $uri
     * @return Uri
     */
    public static function parse(string $uri): Uri
    {
        if (false === ($parts = parse_url((string) $uri))) {
            throw new \InvalidArgumentException('Seriously malformed URI detected');
        }
        
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : '';
        $user = isset($parts['user']) ? $parts['user'] : '';
        $pass = isset($parts['pass']) ? $parts['pass'] : '';
        $host = isset($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) ? $parts['port'] : NULL;
        $path = isset($parts['path']) ? $parts['path'] : '';
        $query = isset($parts['query']) ? $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? $parts['fragment'] : '';
        
        return new static($scheme, $host, $port, $path, $query, $fragment, $user, $pass);
    }

    /**
     * Renders the URI into an encoded string.
     * 
     * @return string
     */
    public function __toString(): string
    {
        if ($this->uriString !== NULL) {
            return $this->uriString;
        }
        
        if (empty($this->scheme)) {
            $uri = $this->host;
        } else {
            $uri = empty($this->host) ? '' : ($this->scheme . '://');
        }
        
        if (!empty($this->username) && !empty($this->host)) {
            $uri .= $this->username;
            
            if (!empty($this->password)) {
                $uri .= ':' . $this->password;
            }
            
            $uri .= '@';
        }
        
        if (empty($this->host)) {
            $uri .= $this->path;
        } else {
            if (empty($this->path)) {
                $uri .= $this->getHostWithPort();
            } else {
                $uri .= $this->getHostWithPort() . '/' . ltrim($this->path, '/');
            }
        }
        
        if (!empty($this->query)) {
            $uri .= '?' . $this->query;
        }
        
        if (!empty($this->fragment)) {
            $uri .= '#' . $this->fragment;
        }
        
        return $this->uriString = $uri;
    }

    /**
     * Render URI as encoded string.
     * 
     * @return string
     */
    public function jsonSerialize(): string
    {
        return (string) $this;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function withScheme(string $scheme): Uri
    {
        $scheme = $this->filterScheme($scheme);
        
        if ($scheme === $this->scheme) {
            return $this;
        }
        
        $uri = clone $this;
        $uri->scheme = $scheme;
        
        return $uri;
    }

    public function getUserInfo(): string
    {
        if (empty($this->password)) {
            return $this->username;
        }
        
        return sprintf('%s:%s', $this->username, $this->password);
    }

    public function withUserInfo(string $user, string $password = NULL): Uri
    {
        $user = trim($user);
        
        $uri = clone $this;
        
        if ($user === '') {
            $uri->username = '';
            $uri->password = '';
        } else {
            $uri->username = $user;
            $uri->password = empty($password) ? '' : trim($password);
        }
        
        return $uri;
    }

    public function getAuthority(): string
    {
        $auth = '';
        
        if (!empty($this->username)) {
            $auth = (empty($this->password) ? $this->username : sprintf('%s:%s', $this->username, $this->password)) . '@';
        }
        
        return $auth . $this->getHostWithPort();
    }

    public function getHost(): string
    {
        return empty($this->host) ? '' : $this->host;
    }

    public function getHostWithPort(bool $forcePort = false): string
    {
        if (empty($this->host)) {
            return '';
        }
        
        $port = $this->port;
        
        if ($this->scheme === 'https' && $this->port === 443) {
            $port = NULL;
        }
        
        if ($this->scheme === 'http' && $this->port === 80) {
            $port = NULL;
        }
        
        if ($port === NULL && $forcePort) {
            $port = ($this->getScheme() === 'https') ? 443 : 80;
        }
        
        return empty($port) ? $this->host : sprintf('%s:%u', $this->host, $port);
    }

    public function withHost(string $host): Uri
    {
        if (!is_string($host) && !method_exists($host, '__toString')) {
            throw new \InvalidArgumentException('Invalid URI host');
        }
        
        $m = NULL;
        
        if (preg_match("'^([^:]+):([0-9]+)'", $host, $m)) {
            $uri = clone $this;
            $uri->host = trim($m[1]);
            $uri->port = (int) $m[2];
        } else {
            $uri = clone $this;
            $uri->host = trim($host);
        }
        
        return $uri;
    }

    public function getPort()
    {
        return $this->port;
    }

    public function withPort(int $port): Uri
    {
        if (is_string($port) && preg_match("'^[0-9]+$'", $port)) {
            $port = (int) $port;
        }
        
        if ($port !== NULL && !is_int($port)) {
            throw new \InvalidArgumentException('Invalid URL port');
        }
        
        if ($port !== NULL) {
            $port = (int) $port;
            
            if ($port < 1 || $port > 65535) {
                throw new \InvalidArgumentException('Invalid URL port');
            }
        }
        
        $uri = clone $this;
        $uri->port = $port;
        
        return $uri;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function withPath(string $path): Uri
    {
        $path = $this->filterPath($path);
        
        $uri = clone $this;
        $uri->path = empty($path) ? '' : $path;
        
        return $uri;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function withQuery(string $query): Uri
    {
        $query = $this->filterQuery($query);
        
        if ($query === $this->query) {
            return $this;
        }
        
        $uri = clone $this;
        $uri->query = $query;
        $uri->parsedQuery = NULL;
        
        return $uri;
    }

    public function hasQueryParam(string $name): bool
    {
        if ($this->parsedQuery === NULL) {
            $this->parsedQuery = self::parseQuery($this->query);
        }
        
        return array_key_exists($name, $this->parsedQuery);
    }

    public function getQueryParam(string $name)
    {
        if ($this->parsedQuery === NULL) {
            $this->parsedQuery = self::parseQuery($this->query);
        }
        
        if (array_key_exists($name, $this->parsedQuery)) {
            return $this->parsedQuery[$name];
        }
        
        if (func_num_args() > 1) {
            return func_get_arg(1);
        }
        
        throw new \OutOfBoundsException(sprintf('Query param not found: "%s"', $name));
    }

    public function getQueryParams(): array
    {
        if ($this->parsedQuery === NULL) {
            $this->parsedQuery = self::parseQuery($this->query);
        }
        
        return $this->parsedQuery;
    }

    public function withQueryParams(array $params): Uri
    {
        $uri = clone $this;
        $uri->parsedQuery = $params;
        $uri->query = $this->filterQuery(self::buildQuery($params));
        
        return $uri;
    }

    public function getFragment(): string
    {
        return $this->fragment ?: '';
    }

    public function withFragment(string $fragment): Uri
    {
        $uri = clone $this;
        $uri->fragment = $this->filterFragment($fragment);
        
        return $uri;
    }

    /**
     * URL-encode the given string.
     * 
     * @param string $string
     * @param bool $encodeSlashes Apply percent encoding to slashes?
     * @return string
     */
    public static function encode(string $string, bool $encodeSlashes = true): string
    {
        return $encodeSlashes ? rawurlencode($string) : implode('/', array_map('rawurlencode', explode('/', (string) $string)));
    }

    /**
     * URL-decode a string in the given character encoding.
     * 
     * @param string $string
     * @return string
     */
    public static function decode(string $string): string
    {
        return rawurldecode($string);
    }

    /**
     * Parse a URL-encoded query string and return a URL-decoded array.
     * 
     * @param string $query
     * @return array<string, mixed>
     */
    public static function parseQuery(string $query): array
    {
        $result = [];
        
        parse_str(ltrim(trim($query), '?'), $result);
        
        return (array) $result;
    }

    /**
     * Build a URL-encoded query string from the given parameter array.
     * 
     * @param array<string, mixed> $query
     * @return string
     */
    public static function buildQuery(array $query, bool $plusEncoded): string
    {
        return empty($query) ? '' : http_build_query($query, NULL, '&', $plusEncoded ? PHP_QUERY_RFC1738 : PHP_QUERY_RFC3986);
    }

    protected function filterScheme(string $scheme): string
    {
        $scheme = strtolower(rtrim(trim($scheme), ':/'));
        
        switch ($scheme) {
            case '':
            case 'http':
            case 'https':
                return $scheme;
        }
        
        throw new \InvalidArgumentException(sprintf('Unsupported URL scheme: "%s"', $scheme));
    }

    protected function filterPath(string $path): string
    {
        $path = trim($path);
        
        if (false !== strpos($path, '?') || false !== strpos($path, '#')) {
            throw new \InvalidArgumentException('Invalid URI path');
        }
        
        $path = preg_replace_callback('/(?:[^a-zA-Z0-9_\-\.~:@&=\+\$,\/;%]+|%(?![A-Fa-f0-9]{2}))/', function ($m) {
            return rawurlencode($m[0]);
        }, $path);
        
        if (empty($path)) {
            return '';
        }
        
        if ($path[0] !== '/') {
            return $path;
        }
        
        return '/' . ltrim($path, '/');
    }

    protected function filterQuery(string $query): string
    {
        $query = ltrim(trim($query), '?');
        
        if (false !== strpos($query, '#')) {
            throw new \InvalidArgumentException('Invalid URI query string');
        }
        
        $parts = explode('&', $query);
        
        foreach ($parts as $index => $part) {
            list ($key, $value) = $this->splitQueryValue($part);
            
            if ($value === null) {
                $parts[$index] = $this->filterQueryOrFragment($key);
                continue;
            }
            
            $parts[$index] = sprintf('%s=%s', $this->filterQueryOrFragment($key), $this->filterQueryOrFragment($value));
        }
        
        return implode('&', $parts);
    }

    protected function splitQueryValue(string $value): array
    {
        $data = explode('=', $value, 2);
        
        if (1 === count($data)) {
            $data[] = NULL;
        }
        
        return $data;
    }

    protected function filterQueryOrFragment(string $query): string
    {
        return preg_replace_callback('/(?:[^a-zA-Z0-9_\-\.~!\$&\'\(\)\*\+,;=%:@\/\?]+|%(?![A-Fa-f0-9]{2}))/', function (array $m) {
            return rawurlencode($m[0]);
        }, $query);
    }

    protected function filterFragment(string $fragment): string
    {
        return $this->filterQueryOrFragment(ltrim(trim($fragment), '#'));
    }
}
