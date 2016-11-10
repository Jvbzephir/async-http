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

use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Util\MediaType;

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
    
    protected $consumes;
    
    protected $produces;
    
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
    
    public function consumes(string ...$mediaType)
    {
        if ($this->consumes === null) {
            $this->consumes = [];
        }
        
        foreach ($mediaType as $type) {
            $this->consumes[] = new MediaType($type);
        }
        
        return $this;
    }
    
    public function canConsume(MediaType $type): bool
    {
        if ($this->consumes === null) {
            return true;
        }
        
        foreach ($this->consumes as $acceptable) {
            if ($type->is($acceptable)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function getConsumedMediaTypes(): array
    {
        return $this->consumes ?? [];
    }
    
    public function produces(string ...$mediaType): RouteHandler
    {
        if ($this->produces === null) {
            $this->produces = [];
        }
        
        foreach ($mediaType as $type) {
            $this->produces[] = new MediaType($type);
        }
        
        return $this;
    }
    
    public function getProducedMediaTypes(): array
    {
        return $this->produces ?? [];
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
                        $boost--;
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
                        $boost--;
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

class RouteMatch
{
    public $handler;
    
    public $params;
    
    public function __construct(RouteHandler $handler, array $params = [])
    {
        $this->handler = $handler;
        $this->params = $params;
    }
}

class RoutingContext
{
    public $level;
    
    protected $allow = [];
    
    protected $matches = [];
    
    public function isMatch(): bool
    {
        return !empty($this->matches);
    }

    public function createNoMatchResponse(): HttpResponse
    {
        if ($this->allow) {
            return new HttpResponse(Http::METHOD_NOT_ALLOWED, [
                'Allow' => \implode(', ', \array_keys($this->allow))
            ]);
        }
        
        return new HttpResponse(Http::NOT_FOUND);
    }
    
    public function createUnsupportedMediaTypeResponse(): HttpResponse
    {
        $response = new HttpResponse(Http::UNSUPPORTED_MEDIA_TYPE);
        $accepted = [];
        
        foreach ($this->matches as $match) {
            foreach ($match->handler->getConsumedMediaTypes() as $type) {
                $accepted[(string) $type] = true;
            }
        }
        
        if ($accepted) {
            \ksort($accepted);
            
            $response = $response->withHeader('Content-Type', 'application/json;charset="utf-8"');
            $response = $response->withBody(new StringBody(\json_encode([
                'status' => Http::UNSUPPORTED_MEDIA_TYPE,
                'reason' => Http::getReason(Http::UNSUPPORTED_MEDIA_TYPE),
                'acceptable' => \array_keys($accepted)
            ], \JSON_UNESCAPED_SLASHES | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_TAG)));
        }
        
        return $response;
    }

    public function addAllowedMethods(array $methods)
    {
        foreach ($methods as $method) {
            $this->allow[$method] = true;
        }
    }
    
    public function addMatch(RouteMatch $match)
    {
        $this->matches[] = $match;
    }
    
    public function getMatches(): array
    {
        return $this->matches;
    }
}

// TODO: Implement hierarchy-based routing by chaining routers...

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

    public function route(RoutingContext $context, string $path, string $method)
    {
        $skip = 0;
        $m = null;
        
        while (true) {
            list ($regex, $routes) = $this->compileRegex($skip);
            
            if ($regex === null) {
                break;
            }
            
            if (!\preg_match($regex, $path, $m)) {
                $skip += $this->chunkSize;
                
                continue;
            }
            
            $route = $routes[$m['MARK']];
            $handler = $route[2];
            
            if ($context->level === null) {
                $context->level = $route[1];
            } elseif ($context->level !== $route[1]) {
                break;
            }
            
            $skip += 1 + (int) $m['MARK'];
            
            if ($handler->isMethodSupported($method)) {
                $context->addMatch(new RouteMatch($handler, $this->extractParams($route, $m)));
            } else {
                $context->addAllowedMethods($handler->getSupportedMethods());
            }
        }
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
    Http::PUT
])->consumes('application/json')->produces('application/json');

$collector->route('page1', '/page/{page}.{format}', [
    Http::PUT
])->consumes('application/json')->produces('application/xml');

$router = new Router($collector->compile());

$request = new HttpRequest('/', Http::POST, [
    'Content-Type' => 'application/json',
    'Accept' => 'application/json;q=.5,*/xml'
]);

$context = new RoutingContext();

$router->route($context, \str_replace('+', '%20', $request->getRequestTarget()), $request->getMethod());

if (!$context->isMatch()) {
    print_r($context->createNoMatchResponse());
    
    exit();
}

$matches = [];

if ($request->hasHeader('Content-Type')) {
    $type = $request->getContentType()->getMediaType();
    
    foreach ($context->getMatches() as $match) {
        if ($match->handler->canConsume($type)) {
            $matches[] = $match;
        }
    }
}

if (empty($matches)) {
    print_r($context->createUnsupportedMediaTypeResponse());
    
    exit();
}

$accept = $request->getAccept();

foreach ($matches as $match) {
    foreach ($match->handler->getProducedMediaTypes() as $type) {
        if ($accept->accepts($type)) {
            print_r($match);
            
            exit();
        }
    }
}

print_r($matches[0]);
