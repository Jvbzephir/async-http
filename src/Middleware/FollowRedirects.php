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
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Http\StringBody;

class FollowRedirects
{
    public function __invoke(HttpRequest $request, NextMiddleware $next): \Generator
    {
        $body = $request->getBody();
        
        if (!$body->isCached()) {
            $request = $request->withBody(new StringBody(yield $body->getContents()));
        }
        
        $i = 0;
        
        do {
            $response = yield from $next($request);
            
            switch ($response->getStatusCode()) {
                case Http::MOVED_PERMANENTLY:
                case Http::FOUND:
                case Http::SEE_OTHER:
                    $request = $request->withMethod(Http::GET);
                    $request = $request->withoutHeader('Content-Type');
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
        } while ($i++ < 3);
    }
}