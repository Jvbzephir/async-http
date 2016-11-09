<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Prototype of a new regex-based HTTP request router with support for additional constraints.

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;

require_once __DIR__ . '/../vendor/autoload.php';

class RouteCollector
{    
    protected $routes = [];

    public function route(string $name, string $pattern, array $methods = null): RouteHandler
    {
        return $this->routes[$name] = new RouteHandler($pattern, $methods);
    }
    
    public function compile(): array
    {
        $parsed = new \SplPriorityQueue();
        
        foreach ($this->routes as $handler) {
            list ($priority, $depth, $regex, $mapping) = $handler->compileRoute();
            
            $parsed->insert(\array_merge([
                $regex,
                $depth,
                $handler
            ], $mapping), $priority);
        }
        
        $result = [];
        
        while (!$parsed->isEmpty()) {
            $result[] = $parsed->extract();
        }
        
        return $result;
    }
}

class RouteHandler
{
    const BOOST_DYNAMIC = -10000;
    
    const BOOST_MULTI = 100;
    
    const BOOST_DEPTH = 1000000;
    
    const TYPE_LITERAL = 'A';
    
    const TYPE_PATH = 'B';
    
    const TYPE_PATH_MULTI = 'C';
    
    const TYPE_PERIOD = 'D';
    
    const TYPE_PERIOD_MULTI = 'E';
    
    protected $pattern;
    
    protected $methods;
    
    public function __construct(string $pattern, array $methods = null)
    {
        $this->pattern = '/' . \ltrim($pattern, '/');
        $this->methods = $methods;
    }
    
    public function isMethodSupported(string $method): bool
    {
        return !$this->methods || \in_array($method, $this->methods, true);
    }
    
    public function getSupportedMethods(): array
    {
        return $this->methods ?? [];
    }
    
    protected function getConstraintBoost(): int
    {
        return $this->methods ? 1 : 0;
    }
    
    public function compileRoute(): array
    {
        $regex = '';
        $params = [];
    
        $boost = $this->getConstraintBoost() + 1000000;
        $lastDepth = 0;
        $depth = 1;
    
        foreach (\preg_split("'(\\{[^\\}]+\\})'", $this->pattern, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) as $part) {
            if ($part[0] !== '{') {
                $regex .= \preg_quote($part, "'");
                $depth += \substr_count($part, '/');
    
                continue;
            }
    
            $name = \substr($part, 1, -1);
            $type = self::TYPE_LITERAL;
            $catch = '[^/]+';
    
            switch ($name[0]) {
                case '/':
                    $depth++;
                    $name = \substr($name, 1);
                    $catch = '/[^/]+';
    
                    if (\substr($name, -1) === '*') {
                        $name = \substr($name, 0, -1);
                        $type = self::TYPE_PATH_MULTI;
                        $catch .= '(?:/[^/]+)*';
                    } else {
                        $type = self::TYPE_PATH;
                    }
                    break;
                case '.':
                    $name = \substr($name, 1);
                    $catch = '\.[^/\.]+?';
    
                    if (\substr($name, -1) === '*') {
                        $name = \substr($name, 0, -1);
                        $type = self::TYPE_PERIOD_MULTI;
                        $catch .= '(?:\.[^/\.]+)*?';
                    } else {
                        $type = self::TYPE_PERIOD;
                    }
                    break;
            }
    
            $regex .= '(' . $catch . ')';
            $params[] = $type . $name;
    
            if ($lastDepth === $depth) {
                $boost += $depth * self::BOOST_MULTI;
            } else {
                $boost += self::BOOST_DYNAMIC;
            }
    
            $lastDepth = $depth;
        }
    
        return [
            $depth * self::BOOST_DEPTH + $boost,
            $depth,
            $regex,
            $params
        ];
    }
}

class Router
{
    protected $routes;
    
    protected $count;
    
    protected $chunkSize;
    
    public function __construct(array $routes, int $chunkSize = 30)
    {
        $this->routes = $routes;
        $this->count = \count($routes);
        $this->chunkSize = $chunkSize;
    }

    public function route(HttpRequest $request)
    {
        $method = $request->getMethod();
        $path = \str_replace('+', '%20', $request->getRequestTarget());
        
        $skip = 0;
        $m = null;
        
        $level = null;
        $allow = [];
        
        while (true) {
            list ($regex, $routes) = $this->compileRegex($skip);
            
            if ($regex === null) {
                break;
            }
            echo $regex, "\n\n";
            if (\preg_match($regex, $path, $m)) {
                $route = $routes[$m['MARK']];
                $handler = $route[2];
                
                if ($level && $level !== $route[1]) {
                    break;
                }
                
                $level = $route[1];
                
                if (!$handler->isMethodSupported($method)) {
                    foreach ($handler->getSupportedMethods() as $allowed) {
                        $allow[] = $allowed;
                    }
                    
                    $skip += 1 + (int) $m['MARK'];
                    
                    continue;
                }
                
                return [
                    Http::OK,
                    $handler,
                    $this->extractParams($route, $m)
                ];
            }
            
            $skip += $this->chunkSize;
        }
        
        if ($allow) {
            return new HttpResponse(Http::METHOD_NOT_ALLOWED, [
                'Allow' => implode(', ', \array_unique($allow))
            ]);
        }
        
        return new HttpResponse(Http::NOT_FOUND);
    }

    protected function compileRegex(int $skip): array
    {
        $regex = "'^()()(?";
        $routes = [];
        $p = 0;
        
        for ($cap = \min($this->count, $skip + $this->chunkSize), $i = $skip; $i < $cap; $i++) {
            $regex .= '|' . $this->routes[$i][0] . '(*MARK:' . $p++ . ')';
            $routes[] = $this->routes[$i];
        }
        
        if (empty($routes)) {
            return [
                null,
                null
            ];
        }
        
        $regex .= ")$'iU";
        
        return [
            $regex,
            $routes
        ];
    }

    protected function extractParams(array $route, array $match): array
    {
        $params = [];
        
        for ($size = \count($route), $i = 3; $i < $size; $i++) {
            switch ($route[$i][0]) {
                case RouteHandler::TYPE_PATH:
                case RouteHandler::TYPE_PERIOD:
                    $params[\substr($route[$i], 1)] = \rawurldecode(\substr($match[$i], 1));
                    break;
                case RouteHandler::TYPE_PATH_MULTI:
                    $params[\substr($route[$i], 1)] = \array_map('rawurldecode', \explode('/', \substr($match[$i], 1)));
                    break;
                case RouteHandler::TYPE_PERIOD_MULTI:
                    $params[\substr($route[$i], 1)] = \array_map('rawurldecode', \explode('.', \substr($match[$i], 1)));
                    break;
                default:
                    $params[\substr($route[$i], 1)] = \rawurldecode($match[$i]);
            }
        }
        
        return $params;
    }
}

$collector = new RouteCollector();

$collector->route('index', '/');

$collector->route('favicon', '/favicon.ico');

$collector->route('page2', '/page{/page*}', [
    Http::POST
]);

$collector->route('page1', '/page/{page}.{format}', [
    Http::PUT
]);

$router = new Router($collector->compile());

$request = new HttpRequest('/page/sub/123.html', Http::POST);

$match = $router->route($request);

print_r($match);

