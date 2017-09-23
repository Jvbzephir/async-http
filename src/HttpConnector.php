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

namespace KoolKode\Async\Http;

use KoolKode\Async\Context;
use KoolKode\Async\Promise;
use KoolKode\Async\Stream\DuplexStream;

interface HttpConnector
{
    public function getPriority(): int;
    
    public function isRequestSupported(HttpRequest $request): bool;
    
    public function isConnected(string $key): bool;
    
    public function getProtocols(): array;
    
    public function isSupported(string $protocol): bool;
    
    public function send(Context $context, HttpRequest $request, ?DuplexStream $stream = null): Promise;
}
