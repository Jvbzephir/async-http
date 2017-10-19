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

namespace KoolKode\Async\Http\Middleware;

use KoolKode\Async\Context;
use KoolKode\Async\Http\HttpRequest;

class PathMatcher implements Middleware
{
    protected $pattern;
    
    protected $regex;
    
    protected $middleware;

    public function __construct(string $pattern, callable $middleware)
    {
        $this->pattern = $pattern;
        $this->middleware = $middleware;
        
        $this->regex = "'^/" . \ltrim($pattern, '/') . "$'i";
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultPriority(): int
    {
        return ($this->middleware instanceof Middleware) ? $this->middleware->getDefaultPriority() : 0;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Context $context, HttpRequest $request, NextMiddleware $next): \Generator
    {
        $path = $request->getUri()->getPath();
        $m = null;
        
        if (!\preg_match($this->regex, $path, $m)) {
            return yield from $next($context, $request, $next);
        }
        
        $path = \ltrim(\substr($path, \strlen($m[0])), '/');
        
        return yield from ($this->middleware)($context, $request, $next, ...\array_slice($m, 1));
    }
}
