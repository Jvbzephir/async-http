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

namespace KoolKode\Async\Http\Response;

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Uri;

/**
 * HTTP response that signals an HTTP redirect to the recipient.
 * 
 * @author Martin Schröder
 */
class RedirectResponse extends HttpResponse
{
    /**
     * Create an HTTP redirect response.
     * 
     * @param string $uri The target URI to redirect the recipient to.
     * @param int $status HTTP status code to be used for the redirect.
     * 
     * @throws \InvalidArgumentException When the given HTTP status code is not usable as a redirect code.
     */
    public function __construct($uri, int $status = Http::SEE_OTHER)
    {
        switch ($status) {
            case Http::MOVED_PERMANENTLY:
            case Http::FOUND:
            case Http::SEE_OTHER:
            case Http::TEMPORARY_REDIRECT:
            case Http::PERMANENT_REDIRECT:
                // These are valid redirect codes ;)
                break;
            default:
                throw new \InvalidArgumentException(\sprintf('Invalid HTTP redirect status code: "%s"', $status));
        }
        
        parent::__construct($status, [
            'Location' => (string) Uri::parse($uri)
        ]);
    }
}
