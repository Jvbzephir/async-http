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

namespace KoolKode\Async\Http;

class HeaderToken implements \Countable
{
    protected $value;
    
    protected $params = [];
    
    public function __construct(string $value, array $params = [])
    {
        $this->value = \trim($value);
        
        foreach ($params as $k => $v) {
            $this->setParam($k, $v);
        }
    }
    
    public function count()
    {
        return \count($this->params);
    }

    public static function parse(string $input): HeaderToken
    {
        $replacements = [];
        $lookup = [];
        $m = null;
        
        if (\preg_match_all("'\"(.*(?<!\\\\))\"'", $input, $m)) {
            foreach ($m[0] as $i => $str) {
                $replacements[$str] = "\"$i\"";
                $lookup["\"$i\""] = $m[1][$i];
            }
        }
        
        $parts = \explode(';', \strtr($input, $replacements));
        $value = \array_shift($parts);
        
        if ($value === '') {
            throw new \InvalidArgumentException();
        }
        
        return new HeaderToken($value, $parts ? self::parseParams($parts, $lookup) : []);
    }

    public static function parseList(string $input, string $separator = ','): array
    {
        $replacements = [];
        $lookup = [];
        $m = null;
        
        if (\preg_match_all("'\"(.*(?<!\\\\))\"'", $input, $m)) {
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
                $tokens[] = new HeaderToken($value, $parts ? self::parseParams($parts, $lookup) : []);
            }
        }
        
        return $tokens;
    }

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

    public function __toString(): string
    {
        $buffer = $this->value;
        
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

    public function getValue(): string
    {
        return $this->value;
    }

    public function hasParam(string $name): bool
    {
        return isset($this->params[$name]);
    }

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

    public function removeParam(string $name): HeaderToken
    {
        unset($this->params[$name]);
        
        return $this;
    }
}