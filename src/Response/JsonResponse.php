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

use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpResponse;

/**
 * HTTP response that transfers JSON-encoded data.
 * 
 * @author Martin Schröder
 */
class JsonResponse extends HttpResponse
{
    /**
     * Create a new JSON-encoded HTTP response.
     * 
     * @param mixed $payload Payload to be transfered.
     * @param bool $encode Encode payload as JSON (turn this off for payloads that are already encoded as JSON)?
     */
    public function __construct($payload, bool $encode = true)
    {
        parent::__construct(Http::OK, [
            'Content-Type' => 'application/json; charset="utf-8"'
        ]);
        
        $this->body = new StringBody($encode ? \json_encode($payload, \JSON_UNESCAPED_SLASHES) : $payload);
    }
}
