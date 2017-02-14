<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Header;

/**
 * Base class for structured HTTP headers.
 * 
 * @author Martin Schröder
 */
class HeaderToken implements \Countable
{
    /**
     * Header value.
     * 
     * @var string
     */
    protected $value;
    
    /**
     * Params as specified by HTTP header format.
     * 
     * @var array
     */
    protected $params = [];
    
    /**
     * Create a new HTTP header token.
     * 
     * @param string $value
     * @param array $params
     */
    public function __construct(string $value, array $params = [])
    {
        $this->value = $value;
        
        foreach ($params as $k => $v) {
            $this->setParam($k, $v);
        }
    }
    
    /**
     * Count the number of params.
     */
    public function count()
    {
        return \count($this->params);
    }

    /**
     * Parse the given input into a single HTTP header token.
     * 
     * @param string $input
     * @return HeaderToken
     */
    public static function parse(string $input): HeaderToken
    {
        $replacements = [];
        $lookup = [];
        $m = null;
        
        if (\preg_match_all("'\"(.*(?<!\\\\)(?:\\\\\\\\)*?)\"'U", $input, $m)) {
            foreach ($m[0] as $i => $str) {
                $replacements[$str] = "\"$i\"";
                $lookup["\"$i\""] = $m[1][$i];
            }
        }
        
        $parts = \explode(';', \strtr($input, $replacements));
        $value = \array_shift($parts);
        
        if ($value === '') {
            throw new \InvalidArgumentException('Cannot parse token without value');
        }
        
        return new static(\trim($value), $parts ? self::parseParams($parts, $lookup) : []);
    }

    /**
     * Parse the given input into a (possibly empty) list of HTTP header tokens.
     * 
     * @param string $input
     * @param string $separator
     * @return array
     */
    public static function parseList(string $input, string $separator = ','): array
    {
        $replacements = [];
        $lookup = [];
        $m = null;
        
        if (\preg_match_all("'\"(.*(?<!\\\\)(?:\\\\\\\\)*?)\"'U", $input, $m)) {
            foreach ($m[0] as $i => $str) {
                $replacements[$str] = "\"$i\"";
                $lookup["\"$i\""] = $m[1][$i];
            }
        }
        
        $tokens = [];
        
        foreach (\explode($separator, \strtr($input, $replacements)) as $token) {
            $parts = \explode(';', $token);
            $value = \array_shift($parts);
            
            if ($value !== '') {
                $tokens[] = new static(\trim($value), $parts ? self::parseParams($parts, $lookup) : []);
            }
        }
        
        return $tokens;
    }

    /**
     * Helper method to parse params into an array.
     * 
     * @param array $parts
     * @param array $lookup
     * @return array
     */
    protected static function parseParams(array $parts, array $lookup): array
    {
        $params = [];
        
        foreach ($parts as $part) {
            $kv = \array_map('trim', \explode('=', $part, 2));
            
            if (isset($kv[1])) {
                $v = $lookup ? \strtr($kv[1], $lookup) : $kv[1];
                
                if (\ctype_digit($v)) {
                    $v = (int) $v;
                } elseif (\is_numeric($v)) {
                    $v = (float) $v;
                }
                
                $params[$kv[0]] = $v;
            } else {
                $params[$kv[0]] = true;
            }
        }
        
        return $params;
    }

    /**
     * Serialize the HTTP header token into a string.
     * 
     * @return string
     */
    public function __toString(): string
    {
        $buffer = (string) $this->value;
        
        foreach ($this->params as $k => $v) {
            if ($v === false) {
                continue;
            }
            
            if ($v === true) {
                $buffer .= ';' . $k;
            } elseif (\is_string($v) && !\ctype_alnum($v)) {
                $buffer .= ';' . $k . '="' . $v . '"';
            } else {
                $buffer .= ';' . $k . '=' . $v;
            }
        }
        
        return $buffer;
    }

    /**
     * Get the value of the header token.
     * 
     * @return string
     */
    public function getValue(): string
    {
        return (string) $this->value;
    }

    /**
     * Get all header params.
     * 
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }
    
    /**
     * Check if the given header param is set.
     * 
     * @param string $name
     * @return bool
     */
    public function hasParam(string $name): bool
    {
        return isset($this->params[$name]);
    }

    /**
     * Get the value of the given header param.
     * 
     * @param string $name
     * @param mixed $default Optional default value to be used when the param is not set.
     * @return mixed
     * 
     * @throws \OutOfBoundsException Is thrown when the param is not set and no default value is given.
     */
    public function getParam(string $name)
    {
        if (isset($this->params[$name])) {
            return $this->params[$name];
        }
        
        if (\func_num_args() > 1) {
            return \func_get_arg(1);
        }
        
        throw new \OutOfBoundsException(\sprintf('Param "%s" not found', $name));
    }

    /**
     * Set the value of a header param.
     * 
     * @param string $name
     * @param mixed $value
     * @return HeaderToken
     * 
     * @throws \InvalidArgumentException When the given param value is not scalar.
     */
    public function setParam(string $name, $value): HeaderToken
    {
        if ($value === null) {
            return $this->removeParam($name);
        }
        
        if (!\is_scalar($value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid value specified for param "%s"', $name));
        }
        
        $this->params[$name] = $value;
        
        return $this;
    }

    /**
     * Remove a header param.
     * 
     * @param string $name
     * @return HeaderToken
     */
    public function removeParam(string $name): HeaderToken
    {
        unset($this->params[$name]);
        
        return $this;
    }
}