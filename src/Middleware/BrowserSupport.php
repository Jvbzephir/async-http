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

use KoolKode\Async\Http\HttpRequest;

/**
 * Middleware that adds browser-specific HTTP headers.
 * 
 * @author Martin Schröder
 */
class BrowserSupport implements Middleware
{
    const FRAME_OPTIONS_ALL = '';

    const FRAME_OPTIONS_SAMEORIGIN = 'SAMEORIGIN';

    const FRAME_OPTIONS_DENY = 'DENY';

    protected $frameOptions;

    public function __construct(string $frameOptions = self::FRAME_OPTIONS_DENY)
    {
        $this->frameOptions = $frameOptions;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultPriority(): int
    {
        return 1000000;
    }

    /**
     * Inject additional browser-related HTTP headers into the HTTP response.
     */
    public function __invoke(HttpRequest $request, NextMiddleware $next): \Generator
    {
        $response = yield from $next($request);
        
        $vary = [];
        
        foreach (\explode(',', $response->getHeaderLine('Vary')) as $token) {
            $vary[\strtolower($token)] = $token;
        }
        
        $vary['origin'] = 'Origin';
        
        $response = $response->withHeader('Vary', \implode(', ', $vary));
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');
        $response = $response->withHeader('X-UA-Compatible', 'IE=Edge');
        $response = $response->withHeader('X-XSS-Protection', '1;mode=block');
        
        if ($this->frameOptions !== self::FRAME_OPTIONS_ALL) {
            $response = $response->withHeader('X-Frame-Options', $this->frameOptions);
        }
        
        return $response;
    }
}
