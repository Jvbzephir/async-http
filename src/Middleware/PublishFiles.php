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

use KoolKode\Async\Context;
use KoolKode\Async\Filesystem\FilesystemProxy;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Response\FileResponse;
use KoolKode\Util\Filesystem as FileUtil;

class PublishFiles implements Middleware
{
    protected const METHODS = [
        Http::GET,
        Http::HEAD
    ];
    
    protected $directory;
    
    protected $ttl;
    
    protected $filesystem;
    
    public function __construct(string $directory, int $ttl = 86400)
    {
        $this->directory = \rtrim(\str_replace('\\', '/', $directory), '/') . '/';
        $this->ttl = $ttl;
        
        $this->filesystem = new FilesystemProxy();
    }
    
    /**
     * {@inheritdoc}
     */
    public function getDefaultPriority(): int
    {
        return 0;
    }
    
    /**
     * {@inheritdoc}
     */
    public function __invoke(Context $context, HttpRequest $request, NextMiddleware $next, ?string $path = null): \Generator
    {
        if (!\in_array($request->getMethod(), self::METHODS, true)) {
            return yield from $next($context, $request);
        }
        
        $path = '/' . \trim($path ?? $request->getUri()->getPath(), '/');
        
        $file = FileUtil::normalizePath($this->directory . \substr($path, 1));
        
        if (0 !== \strpos($file, $this->directory)) {
            return yield from $next($context, $request);
        }
        
        if (!yield $this->filesystem->isFile($context, $file)) {
            return yield from $next($context, $request);
        }
        
        $response = new FileResponse($file);
        $response = $response->withHeader('Cache-Control', \sprintf('public, max-age=%u', $this->ttl));
        
        if ($request->getProtocolVersion() === '1.0') {
            $response = $response->withHeader('Expires', \gmdate(Http::DATE_RFC1123, \time() + $this->ttl));
        }
        
        return $response;
    }
}
