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

use KoolKode\Async\Http\Body\BufferedBody;
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Uri;

/**
 * Middleware that automatically follows HTTP redirects.
 * 
 * @author Martin Schröder
 */
class FollowRedirects
{
    /**
     * Max number of redirects that are allowed for a single request.
     * 
     * @var int
     */
    protected $maxRedirects;
    
    /**
     * Create new HTTP redirect middleware.
     * 
     * @param int $maxRedirects Max number of redirects that are allowed for a single request.
     */
    public function __construct(int $maxRedirects = 5)
    {
        $this->maxRedirects = $maxRedirects;
    }
    
    /**
     * Automatically follow HTTP redirects according to HTTP status code and location header.
     * 
     * Uncached HTTP request bodies will be cached prior to being sent to the remote endpoint.
     * 
     * @param HttpRequest $request
     * @param NextMiddleware $next
     * @return HttpResponse
     * 
     * @throws TooManyRedirectsException When the maximum number of redirects for a single HTTP request has been exceeded.
     */
    public function __invoke(HttpRequest $request, NextMiddleware $next): \Generator
    {
        $body = $request->getBody();
        
        if (!$body->isCached()) {
            $request = $request->withBody(new BufferedBody(yield $body->getReadableStream()));
        }
        
        for ($i = -1; $i < $this->maxRedirects; $i++) {
            $response = yield from $next($request);
            
            switch ($response->getStatusCode()) {
                case Http::MOVED_PERMANENTLY:
                case Http::FOUND:
                case Http::SEE_OTHER:
                    $request = $request->withMethod(Http::GET);
                    $request = $request->withoutHeader('Content-Type');
                    $request = $request->withBody(new StringBody());
                    break;
                case Http::TEMPORARY_REDIRECT:
                case Http::PERMANENT_REDIRECT:
                    // Replay request to a different URL.
                    break;
                default:
                    return $response;
            }
            
            try {
                $uri = Uri::parse($response->getHeaderLine('Location'));
                $target = $uri->getPath();
                
                if ('' !== ($query = $uri->getQuery())) {
                    $target .= '?' . $query;
                }
                
                $request = $request->withUri($uri);
                $request = $request->withRequestTarget($target);
            } finally {
                yield $response->getBody()->discard();
            }
        }
        
        throw new TooManyRedirectsException(\sprintf('Limit of %s HTTP redirects exceeded', $this->maxRedirects));
    }
}