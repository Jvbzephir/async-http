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

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Context;
use KoolKode\Async\Placeholder;
use KoolKode\Async\Promise;
use KoolKode\Async\Success;
use KoolKode\Async\Http\HttpConnector;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Stream\DuplexStream;

class Http2Connector implements HttpConnector
{
    protected $connecting = [];
    
    protected $connections = [];
    
    public function getPriority(): int
    {
        return 20;
    }

    public function isRequestSupported(HttpRequest $request): bool
    {
        if ($request->getUri()->getScheme() != 'https') {
            return false;
        }
        
        return (float) $request->getProtocolVersion() >= 2;
    }

    public function isConnected(Context $context, string $key): Promise
    {
        if (isset($this->connecting[$key])) {
            $placeholder = new Placeholder($context);
            $placeholder->resolve($this->connecting[$key]);
            
            return $placeholder->promise();
        }
        
        return new Success($context, isset($this->connections[$key]));
    }

    public function getProtocols(): array
    {
        return [
            'h2'
        ];
    }
    
    public function isSupported(string $protocol): bool
    {
        return $protocol == 'h2';
    }
    
    public function send(Context $context, HttpRequest $request, ?DuplexStream $stream = null): Promise
    {
        // TODO: Implement HTTP/2 request handling.
    }
}
