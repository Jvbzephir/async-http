<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Header;

/**
 * Helper class for working with HTTP header params.
 * 
 * @author Martin Schröder
 */
abstract class Attributes
{
    public static function parseAttributes(string $str): array
    {
        $attr = [];
        $quoted = [];
        
        $str = preg_replace_callback("'\"((?:\\\\(?:\"|\\\\)|[^\"])*)\"'", function (array $m) use (& $quoted) {
            $key = '"' . count($quoted) . '"';
            $quoted[$key] = strtr($m[1], [
                '\\\\' => '\\',
                '\\"' => '"'
            ]);
            
            return $key;
        }, $str);
        
        $m = NULL;
        preg_match_all("'\s*?([^=;]+)\s*?(?:=\s*?(.*)\s*?)?(?:$|;)'iU", $str, $m);
        
        foreach ($m[1] as $i => $key) {
            if ($m[2][$i] === '') {
                $attr[$key] = true;
            } else {
                $val = strtr($m[2][$i], $quoted);
                
                if (preg_match("'^[0-9]+$'", $val)) {
                    $attr[$key] = (int) $val;
                } elseif (preg_match("'^[0-9]*\\.[0-9]+$'", $val)) {
                    $attr[$key] = (float) $val;
                } else {
                    $attr[$key] = $val;
                }
            }
        }
        
        return $attr;
    }
    
    public static function splitValues(string $str, string $separator = ','): array
    {
        $parts = [];
        $quoted = [];
        
        $str = preg_replace_callback("'\"((?:\\\\(?:\"|\\\\)|[^\"])*)\"'", function (array $m) use (& $quoted) {
            $key = '"' . count($quoted) . '"';
            $quoted[$key] = $m[0];
            
            return $key;
        }, $str);
        
        foreach (explode($separator, $str) as $part) {
            if (trim($part) === '') {
                continue;
            }
            
            $parts[] = trim(strtr($part, $quoted));
        }
        
        return $parts;
    }
    
    public static function buildAttributeString(array $attr): string
    {
        $str = '';
        
        foreach ($attr as $k => $v) {
            if (\is_bool($v)) {
                if ($v) {
                    $str .= ';' . $k;
                }
                
                continue;
            }
            
            $str .= ';' . $k;
            
            if (\is_int($v) || \is_float($v)) {
                $str .= '=' . $v;
            } else {
                $str .= '="' . strtr($v, [
                    '"' => '\"',
                    '\\' => '\\\\'
                ]) . '"';
            }
        }
        
        return $str;
    }
}
