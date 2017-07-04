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

use KoolKode\Async\Filesystem\Filesystem;
use KoolKode\Async\Filesystem\FilesystemProxy;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Response\FileResponse;
use KoolKode\Util\Filesystem as FileUtil;

/**
 * Publishes all files within a directory (and all nested directories).
 * 
 * @author Martin Schröder
 */
class PublishFiles
{
    /**
     * Absolute path of the base directory on the local filesystem.
     * 
     * @var string
     */
    protected $directory;

    /**
     * Base path to stripped from the HTTP request target.
     * 
     * @var string
     */
    protected $basePath;

    /**
     * Client cache lifetime in seconds.
     * 
     * @var int
     */
    protected $ttl;
    
    protected $filesystem;

    /**
     * Create a new file publisher middleware.
     * 
     * @param string $directory Absolute path of the base directory on the local filesystem.
     * @param string $basePath Base path to stripped from the HTTP request target.
     * @param int $ttl Client cache lifetime in seconds (defualts to 1 day).
     */
    public function __construct(string $directory, string $basePath = '/', int $ttl = 86400)
    {
        $this->directory = \rtrim(\str_replace('\\', '/', $directory), '/') . '/';
        $this->basePath = \rtrim('/' . \trim($basePath, '/'), '/') . '/';
        $this->ttl = $ttl;
        
        $this->filesystem = new FilesystemProxy();
    }

    /**
     * Check if the HTTP request matches a public file and server it as needed.
     */
    public function __invoke(HttpRequest $request, NextMiddleware $next): \Generator
    {
        static $methods = [
            Http::HEAD,
            Http::GET
        ];
        
        if (!\in_array($request->getMethod(), $methods, true)) {
            return yield from $next($request);
        }
        
        $path = '/' . \trim($request->getRequestTarget(), '/');
        
        if ($this->basePath !== '/') {
            if (0 !== \strpos($path, $this->basePath)) {
                return yield from $next($request);
            }
            
            $path = \substr($path, \strlen($this->basePath) - 1);
        }
        
        $file = FileUtil::normalizePath($this->directory . \substr($path, 1));
        
        if (0 !== \strpos($file, $this->directory)) {
            return yield from $next($request);
        }
        
        if (!yield $this->filesystem->isFile($file)) {
            return yield from $next($request);
        }
        
        return $this->createResponse($request, $file);
    }
    
    public function setFilesystem(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Create an HTTP file response for the matched file.
     * 
     * @param HttpRequest $request
     * @param string $file
     * @return HttpResponse
     */
    protected function createResponse(HttpRequest $request, string $file): HttpResponse
    {
        $response = new FileResponse($file);
        $response = $response->withHeader('Cache-Control', \sprintf('public, max-age=%u', $this->ttl));
        
        if ($request->getProtocolVersion() === '1.0') {
            $response = $response->withHeader('Expires', \gmdate(Http::DATE_RFC1123, \time() + $this->ttl));
        }
        
        return $response;
    }
}
