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
use KoolKode\Async\Http\Middleware\MiddlewareSupported;
use KoolKode\Async\Http\Middleware\NextMiddleware;

class HttpHost
{
    use MiddlewareSupported;
    
    protected $action;
    
    protected $encryption = [];
    
    public function __construct(callable $action)
    {
        $this->action = $action;
    }

    public function isEncrypted(): bool
    {
        return !empty($this->encryption);
    }

    public function getEncryptionSettings(): array
    {
        return $this->encryption;
    }

    public function withEncryption(string $certFile, ?string $name = null): self
    {
        if (!\is_file($certFile)) {
            throw new \InvalidArgumentException(\sprintf('Certificate file not found: "%s"', $certFile));
        }
        
        if ($name === null) {
            $name = @\openssl_x509_parse(\file_get_contents($certFile))['subject']['CN'] ?? null;
            
            if ($name === null || $name === '') {
                throw new \RuntimeException(\sprintf('Failed to read CN from certificate "%s"', $certFile));
            }
            
            if ($name[0] == '*') {
                $name = \preg_replace("'^(?:\\*\\.)+'", '', $name);
            }
        }
        
        $host = clone $this;
        $host->encryption = [
            'peer_name' => $name,
            'certificate' => $certFile
        ];
        
        return $host;
    }

    public function handleRequest(Context $context, HttpRequest $request, callable $responder): \Generator
    {
        $next = new NextMiddleware($this->middlewares, function (Context $context, HttpRequest $request) use ($responder) {
            return yield from $responder($context, ($this->action)($context, $request));
        });
        
        return yield from $next($context, $request);
    }
}
