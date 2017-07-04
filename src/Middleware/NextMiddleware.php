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

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Logger;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * HTTP middleware dispatcher.
 * 
 * @author Martin Schröder
 */
class NextMiddleware implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    /**
     * Registered middlewares sorted by priority.
     * 
     * @var array
     */
    protected $middlewares;

    /**
     * The target to be invoked if all middlewares delegate dispatching of the HTTP request.
     * 
     * @var callable
     */
    protected $target;
    
    /**
     * Current middleware processing index.
     * 
     * @var int
     */
    protected $index = 0;

    /**
     * Create a new HTTP middleware dispatcher.
     * 
     * @param array $middlewares Registered middlewares sorted by priority.
     * @param callable $target The target to be invoked if all middlewares delegate dispatching of the HTTP request.
     */
    public function __construct(array $middlewares, callable $target)
    {
        $this->middlewares = $middlewares;
        $this->target = $target;
        $this->logger = new Logger(static::class);
    }

    /**
     * Wrap the given middleware and target into a middleware dispatcher.
     * 
     * @param callable $middleware
     * @param callable $target
     * @return NextMiddleware
     */
    public static function wrap(callable $middleware, callable $target): NextMiddleware
    {
        return new static([
            new RegisteredMiddleware($middleware, 0)
        ], $target);
    }

    /**
     * Invoke next HTTP middleware or the decorated action.
     * 
     * @throws \RuntimeException When the middleware / action does not return / resolve into an HTTP response.
     */
    public function __invoke(HttpRequest $request): \Generator
    {
        try {
            if (isset($this->middlewares[$this->index])) {
                $response = ($this->middlewares[$this->index++]->callback)($request, $this);
            } else {
                $response = ($this->target)($request);
            }
            
            if ($response instanceof \Generator) {
                $response = yield from $response;
            }
            
            if (!$response instanceof HttpResponse) {
                throw new \RuntimeException(\sprintf('Middleware must return an HTTP response, given %s', \is_object($response) ? \get_class($response) : \gettype($response)));
            }
        } catch (\Throwable $e) {
            $response = Http::respondToError($e, $this->logger);
        }
        
        return $response;
    }
}
