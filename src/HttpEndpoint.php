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
use Psr\Log\LogLevel;

class HttpEndpoint
{
    use MiddlewareSupported;
    
    protected $drivers;

    protected $bind = [];
    
    protected $defaultHost;
    
    protected $defaultEncryptedHost;
    
    protected $hosts = [];
    
    protected $proxy;
    
    protected $errorLogging;

    public function __construct(HttpDriver ...$drivers)
    {
        if (empty($drivers)) {
            throw new \InvalidArgumentException('At least one HTTP driver is required');
        }
        
        $this->drivers = $drivers;
        
        \usort($this->drivers, function (HttpDriver $a, HttpDriver $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }

    public function withAddress(string $address, bool $encrypted = false): self
    {
        $endpoint = clone $this;
        $endpoint->bind[$address] = $encrypted;
        
        return $endpoint;
    }

    public function withDefaultHost(HttpHost $host, ?string $name = null): self
    {
        if ($host->isEncrypted()) {
            throw new \InvalidArgumentException('Host must not be encrypted');
        }
        
        if ($name === null) {
            $hostname = \gethostname();
            
            if ($hostname != ($ip = @\gethostbyname($hostname))) {
                if ($ip != ($name = @\gethostbyaddr($ip))) {
                    $hostname = $name;
                }
            }
            
            $name = \strtolower($hostname);
        }
        
        $endpoint = clone $this;
        $endpoint->defaultHost = [
            $name,
            $host
        ];
        
        return $endpoint;
    }
    
    public function withDefaultEncryptedHost(HttpHost $host, ?string $certFile = null): self
    {
        if ($certFile !== null) {
            $host = $host->withEncryption($certFile);
        }
        
        if (!$host->isEncrypted()) {
            throw new \InvalidArgumentException('Host must be encrypted');
        }
        
        $endpoint = clone $this;
        $endpoint->defaultEncryptedHost = $host;
        
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

    public function withReverseProxy(ReverseProxySettings $proxy): self
    {
        $endpoint = clone $this;
        $endpoint->proxy = $proxy;
        
        return $endpoint;
    }
    
    public function withErrorLogging(?string $level = LogLevel::ERROR): self
    {
        $endpoint = clone $this;
        $endpoint->errorLogging = $level;
        
        return $endpoint;
    }

    public function listen(Context $context): Promise
    {
        if (empty($this->defaultHost) && !$this->defaultEncryptedHost && !$this->hosts) {
            throw new \RuntimeException('Cannot start HTTP endpoint without a host defined');
        }
        
        if (empty($this->bind)) {
            $bind = $this->getDefaultBindAddresses();
        } else {
            $bind = $this->bind;
        }
        
        $tls = new ServerEncryption();
        
        if ($this->defaultEncryptedHost) {
            $settings = $this->defaultEncryptedHost->getEncryptionSettings();
            
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

    protected function getDefaultBindAddresses(): array
    {
        $bind = [];
        
        if (!empty($this->defaultHost)) {
            $bind['tcp://127.0.0.1:80'] = false;
        } else {
            foreach ($this->hosts as $host) {
                if (!$host->isEncrypted()) {
                    $bind['tcp://127.0.0.1:80'] = false;
                    
                    break;
                }
            }
        }
        
        if ($this->defaultEncryptedHost) {
            $bind['tcp://127.0.0.1:443'] = true;
        } else {
            foreach ($this->hosts as $host) {
                if ($host->isEncrypted()) {
                    $bind['tcp:/127.0.0.1:443'] = false;
                    
                    break;
                }
            }
        }
        
        return $bind;
    }

    protected function run(Context $context, array $servers): \Generator
    {
        $accept = new Accept();
        $accept = $accept->withTcpNodelay(true);
        $accept = $accept->withDropFailed(true);
        
        $listeners = [];
        
        try {
            $ctx = $this->errorLogging ? $context->withErrorLogging($this->errorLogging) : $context;
            
            foreach ($servers as $server) {
                $listeners[] = $server->listen($ctx, \Closure::fromCallable([
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
        
        yield $driver->listen($context, $socket, function (Context $context, HttpRequest $request, callable $responder) use ($socket) {
            $secure = $socket->isEncrypted();
            $uri = $request->getUri()->withScheme($secure ? 'https' : 'http');
            
            if (!$request->hasHeader('Host', false)) {
                if ($secure) {
                    $uri = $uri->withHost($this->defaultEncryptedHost->getEncryptionSettings()['peer_name']);
                } else {
                    $uri = $uri->withHost(\array_filter($this->bind) ? $this->defaultHost[0] : 'localhost');
                }
            }
            
            $request = $request->withUri($uri);
            $m = null;
            
            if (\preg_match("'^(.+):[1-9][0-9]*$'", $socket->getRemotePeer(), $m)) {
                $request = $request->withAddress($address = $m[1]);
            } else {
                $request = $request->withAddress($address = '127.0.0.1');
            }
            
            if ($this->proxy && $this->proxy->isTrustedProxy($address)) {
                $request = $this->applyProxySettings($request);
            }
            
            $name = $request->getUri()->getHostWithPort(true);
            $handler = null;
            
            foreach ($this->hosts as $matcher => $host) {
                if ($secure && !$host->isEncrypted()) {
                    continue;
                }
                
                if ($host->isEncrypted()) {
                    continue;
                }
                
                if (\preg_match($matcher, $name)) {
                    $handler = $host;
                    
                    break;
                }
            }
            
            if ($handler === null) {
                $handler = $secure ? $this->defaultEncryptedHost : $this->defaultHost[1];
            }
            
            $next = new NextMiddleware($this->middlewares, function (Context $context, HttpRequest $request) use ($handler, $responder) {
                return yield from $handler->handleRequest($context, $request, $responder);
            });
            
            return yield from $next($context, $request);
        });
    }
    
    protected function applyProxySettings(HttpRequest $request): HttpRequest
    {
        if (null !== ($scheme = $this->proxy->getScheme($request))) {
            $request = $request->withUri($request->getUri()->withScheme($scheme));
        }
        
        if (null !== ($host = $this->proxy->getHost($request))) {
            $request = $request->withUri($request->getUri()->withHost($host));
        }
        
        if ($addresses = $this->proxy->getAddresses($request)) {
            $request = $request->withAddress(...\array_merge($addresses, (array) $request->getClientAddress()));
        }
        
        return $request;
    }
}
