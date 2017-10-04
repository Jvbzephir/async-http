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
use KoolKode\Async\Http\Middleware\MiddlewareSupported;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Socket\Accept;
use KoolKode\Async\Socket\Listener;
use KoolKode\Async\Socket\ServerEncryption;
use KoolKode\Async\Socket\ServerFactory;
use KoolKode\Async\Socket\Socket;

class HttpEndpoint
{
    use MiddlewareSupported;
    
    protected $drivers;

    protected $bind = [];
    
    protected $defaultHost;
    
    protected $hosts = [];

    public function __construct(HttpDriver ...$drivers)
    {
        if (empty($drivers)) {
            throw new \InvalidArgumentException('At least one HTTP driver is required');
        }
        
        $this->drivers = $drivers;
        
        \usort($this->drivers, function (HttpDriver $a, HttpDriver $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
        
        $this->defaultHost = new HttpHost(static function () {
            return new HttpResponse(Http::SERVICE_UNAVAILABLE);
        });
    }

    public function withAddress(string $address, bool $encrypted = false): self
    {
        $endpoint = clone $this;
        $endpoint->bind[$address] = $encrypted;
        
        return $endpoint;
    }

    public function withDefaultHost(HttpHost $host): self
    {
        $endpoint = clone $this;
        $endpoint->defaultHost = $host;
        
        return $endpoint;
    }

    public function withHost(string $matcher, HttpHost $host): self
    {
        if (false === \strpos($matcher, ':')) {
            $matcher = $matcher . ':*';
        }
        
        $matcher = "'^" . \str_replace('\\*', '.+', \preg_quote($matcher, "'")) . "$'i";
        
        $endpoint = clone $this;
        $endpoint->hosts[$matcher] = $host;
        
        return $endpoint;
    }

    public function listen(Context $context): Promise
    {
        if (empty($this->bind)) {
            if ($this->defaultHost->isEncrypted()) {
                $bind = [
                    'tcp://0.0.0.0:443' => true
                ];
            } else {
                $bind = [
                    'tcp://0.0.0.0:80' => false
                ];
            }
        } else {
            $bind = $this->bind;
        }
        
        $tls = new ServerEncryption();
        
        if ($this->defaultHost->isEncrypted()) {
            $settings = $this->defaultHost->getEncryptionSettings();
            
            $tls = $tls->withPeerName($settings['peer_name']);
            $tls = $tls->withDefaultCertificate($settings['certificate']);
            
            $tls = $tls->withAlpnProtocols(...\array_merge(...\array_map(function (HttpDriver $driver) {
                return $driver->getProtocols();
            }, $this->drivers)));
        }
        
        foreach ($this->hosts as $host) {
            if ($host->isEncrypted()) {
                $settings = $host->getEncryptionSettings();
                
                $tls = $tls->withCertificate($settings['peer_name'], $settings['certificate']);
            }
        }
        
        $servers = [];
        
        try {
            foreach ($bind as $url => $encrypted) {
                $servers[] = (new ServerFactory($url, $encrypted ? $tls : null))->createServer($context->getLoop());
                
                $context->info('Server listening on {url}', [
                    'url' => $url,
                    'encrypted' => (int) $encrypted
                ]);
            }
        } catch (\Throwable $e) {
            foreach ($servers as $server) {
                $server->close();
            }
        }
        
        return $context->task($this->run($context, $servers));
    }

    protected function run(Context $context, array $servers): \Generator
    {
        $accept = new Accept();
        $accept = $accept->withTcpNodelay(true);
        $accept = $accept->withDropFailed(true);
        
        $listeners = [];
        
        try {
            foreach ($servers as $server) {
                $listeners[] = $server->listen($context, \Closure::fromCallable([
                    $this,
                    'handleConnection'
                ]), $accept);
            }
            
            yield $context->all(\array_map(function (Listener $listener) {
                return $listener->join();
            }, $listeners));
        } finally {
            foreach ($listeners as $listener) {
                $listener->close();
            }
            
            foreach ($servers as $server) {
                $server->close();
            }
        }
    }

    protected function handleConnection(Context $context, Socket $socket): \Generator
    {
        $alpn = $socket->getAlpnProtocol() ?? '';
        $driver = null;
        
        foreach ($this->drivers as $candidate) {
            if ($candidate->isSupported($alpn)) {
                $driver = $candidate;
                
                break;
            }
        }
        
        if ($driver === null) {
            throw new \RuntimeException(\sprintf('No driver supports ALPN protocol "%s"', $alpn));
        }
        
        yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request) use ($socket) {
            $uri = $request->getUri()->withScheme($socket->isEncrypted() ? 'https' : 'http');
            
            $request = $request->withUri($uri);
            
            return yield from $this->handleRequest($context, $request);
        });
    }

    protected function handleRequest(Context $context, HttpRequest $request): \Generator
    {
        $next = new NextMiddleware($this->middlewares, function (Context $context, HttpRequest $request) {
            $secure = ($request->getUri()->getScheme() == 'https');
            $name = $request->getUri()->getHostWithPort(true);
            
            if ($name !== '') {
                foreach ($this->hosts as $matcher => $host) {
                    if ($secure && !$host->isEncrypted()) {
                        continue;
                    }
                    
                    if ($host->isEncrypted()) {
                        continue;
                    }
                    
                    if (\preg_match($matcher, $name)) {
                        return yield from $host->handleRequest($context, $request);
                    }
                }
            }
            
            return yield from $this->defaultHost->handleRequest($context, $request);
        });
        
        return yield from $next($context, $request);
    }
}
