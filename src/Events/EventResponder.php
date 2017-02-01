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

namespace KoolKode\Async\Http\Events;

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Responder\Responder;

/**
 * HTTP responder that converts an SSE event source into an HTTP response.
 * 
 * @author Martin Schröder
 */
class EventResponder implements Responder
{
    /**
     * {@inheritdoc}
     */
    public function getDefaultPriority(): int
    {
        return 0;
    }

    /**
     * Convert SSE event source into an HTTP response.
     */
    public function __invoke(HttpRequest $request, $source)
    {
        if ($source instanceof EventSource) {
            return new HttpResponse(Http::OK, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache'
            ], new EventBody($source));
        }
    }
}
