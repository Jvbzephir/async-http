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

use KoolKode\Async\Awaitable;
use KoolKode\Async\Stream\DuplexStream;

interface HttpConnector
{
    public function getProtocols(): array;
    
    public function isSupported(string $protocol, array $meta = []): bool;
    
    public function send(DuplexStream $stream, HttpRequest $request, bool $keepAlive = true): Awaitable;
}
