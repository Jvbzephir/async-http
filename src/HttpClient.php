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
use KoolKode\Async\Placeholder;
use KoolKode\Async\Promise;
use KoolKode\Async\Channel\IterableChannel;
use KoolKode\Async\Channel\Pipeline;
use KoolKode\Async\Http\Middleware\MiddlewareSupported;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Socket\ClientEncryption;
use KoolKode\Async\Socket\ClientFactory;
use KoolKode\Async\Socket\Connect;

/**
 * HTTP client that supports multiple HTTP protocol versions via connectors.
 * 
 * @author Martin Schröder
 */
class HttpClient
{
    use MiddlewareSupported;
    
    /**
     * Optional base URI for HTTP requests using relative URIs.
     * 
     * @var Uri
     */
    protected $baseUri;
    
    /**
     * Client-specific settings to be used for all HTTP requests.
     * 
     * @var ClientSettings
     */
    protected $clientSettings;
    
    /**
     * Registered HTTP connectors sorted by priority (higher priorities first).
     * 
     * @var array
     */
    protected $connectors;
    
    /**
     * Pending connection attempts.
     * 
     * @var array
     */
    protected $connecting = [];
    
    /**
     * Create a new HTTP client.
     * 
     * @param HttpConnector $connectors HTTP connectors.
     * 
     * @throws \InvalidArgumentException If no HTTP connector was given.
     */
    public function __construct(HttpConnector ...$connectors)
    {
        if (empty($connectors)) {
            throw new \InvalidArgumentException('At least one HTTP connector is required');
        }
        
        $this->connectors = $connectors;
        $this->clientSettings = new ClientSettings();
        
        \usort($this->connectors, function (HttpConnector $a, HttpConnector $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }
    
    /**
     * Set a base URI that will be prepended to all HTTP requests with a relative URL.
     * 
     * @param mixed $uri String or URI object representing the base URI to be used.
     * 
     * @throws \InvalidArgumentException When an invalid or relative URI is given.
     */
    public function withBaseUri($uri): self
    {
        $uri = Uri::parse($uri)->withQuery('')->withFragment('');
        
        switch ($uri->getScheme()) {
            case 'http':
            case 'https':
                // OK
                break;
            default:
                throw new \InvalidArgumentException(\sprintf('Unsupported base URI scheme: "%s"', $uri->getScheme()));
        }
        
        if ($uri->getHost() === '') {
            throw new \InvalidArgumentException('Base URI must specifiy a host');
        }
        
        $client = clone $this;
        $client->baseUri = $uri;
        
        return $client;
    }

    /**
     * Creates an HTTP request builder backed by the client.
     * 
     * @param string $uri The requested URI (relative URIs will be prpended with the client's base URI).
     * @param string $method HTTP request method.
     * @param array $headers HTTP request headers.
     */
    public function request(string $uri = '', string $method = Http::GET, array $headers = []): RequestBuilder
    {
        $builder = new RequestBuilder($this, $this->normalizeUri(Uri::parse($uri)), $method);
        
        foreach ($headers as $k => $v) {
            $builder->header($k, ...(array) $v);
        }
        
        return $builder->attribute(ClientSettings::class, $this->clientSettings);
    }

    /**
     * Creates a HTTP OPTIONS request builder backed by the client.
     * 
     * @param string $uri The requested URI (relative URIs will be prpended with the client's base URI).
     * @param array $headers HTTP request headers.
     */
    public function options(string $uri = '', array $headers = []): RequestBuilder
    {
        return $this->request($uri, Http::OPTIONS, $headers);
    }

    /**
     * Creates a HTTP HEAD request builder backed by the client.
     *
     * @param string $uri The requested URI (relative URIs will be prpended with the client's base URI).
     * @param array $headers HTTP request headers.
     */
    public function head(string $uri = '', array $headers = []): RequestBuilder
    {
        return $this->request($uri, Http::HEAD, $headers);
    }

    /**
     * Creates a HTTP GET request builder backed by the client.
     *
     * @param string $uri The requested URI (relative URIs will be prpended with the client's base URI).
     * @param array $headers HTTP request headers.
     */
    public function get(string $uri = '', array $headers = []): RequestBuilder
    {
        return $this->request($uri, Http::GET, $headers);
    }

    /**
     * Creates a HTTP POST request builder backed by the client.
     *
     * @param string $uri The requested URI (relative URIs will be prpended with the client's base URI).
     * @param array $headers HTTP request headers.
     */
    public function post(string $uri = '', array $headers = []): RequestBuilder
    {
        return $this->request($uri, Http::POST, $headers);
    }

    /**
     * Creates a HTTP PUT request builder backed by the client.
     *
     * @param string $uri The requested URI (relative URIs will be prpended with the client's base URI).
     * @param array $headers HTTP request headers.
     */
    public function put(string $uri = '', array $headers = []): RequestBuilder
    {
        return $this->request($uri, Http::PUT, $headers);
    }

    /**
     * Creates a HTTP PATCH request builder backed by the client.
     *
     * @param string $uri The requested URI (relative URIs will be prpended with the client's base URI).
     * @param array $headers HTTP request headers.
     */
    public function patch(string $uri = '', array $headers = []): RequestBuilder
    {
        return $this->request($uri, Http::PATCH, $headers);
    }

    /**
     * Creates a HTTP DELETE request builder backed by the client.
     *
     * @param string $uri The requested URI (relative URIs will be prpended with the client's base URI).
     * @param array $headers HTTP request headers.
     */
    public function delete(string $uri = '', array $headers = []): RequestBuilder
    {
        return $this->request($uri, Http::DELETE, $headers);
    }

    /**
     * Send a single HTTP request and return the response.
     * 
     * @param Context $context Async execution context.
     * @param mixed $request HTTP request or request builder.
     * @return HttpResponse HTTP response.
     * 
     * @throws \InvalidArgumentException If an invalid HTTP request (or builder) has been passed.
     */
    public function send(Context $context, $request): Promise
    {
        if ($request instanceof RequestBuilder) {
            $request = $request->build();
        }
        
        if (!$request instanceof HttpRequest) {
            throw new \InvalidArgumentException('HttpRequest or RequestBuilder expected');
        }
        
        $request = $request->withUri($this->normalizeUri($request->getUri()));
        
        $next = new NextMiddleware($this->middlewares, function (Context $context, HttpRequest $request) {
            $uri = $request->getUri();
            $key = $uri->getScheme() . '://' . $uri->getHostWithPort(true);
            
            while (isset($this->connecting[$key])) {
                $this->connecting[$key][] = $placeholder = new Placeholder($context);
                
                yield $placeholder->promise();
            }
            
            $supported = [];
            
            foreach ($this->connectors as $connector) {
                if ($connector->isRequestSupported($request)) {
                    if (yield $connector->isConnected($context, $key)) {
                        return yield $connector->send($context, $request);
                    }
                    
                    $supported[] = $connector;
                }
            }
            
            if (empty($supported)) {
                throw new \RuntimeException('Unable to find connector that supports the given HTTP request');
            }
            
            $protocols = \array_merge(...\array_map(function (HttpConnector $connector) {
                return $connector->getProtocols();
            }, $supported));
            
            $this->connecting[$key] = [];
            
            try {
                $socket = yield from $this->connectSocket($context, $uri, $protocols);
                
                try {
                    $connector = $this->chooseConnector($supported, $socket->getAlpnProtocol() ?? '');
                } catch (\Throwable $e) {
                    $socket->close();
                    
                    throw $e;
                }
                
                $promise = $connector->send($context, $request, $socket);
            } finally {
                $connecting = $this->connecting[$key];
                unset($this->connecting[$key]);
                
                foreach ($connecting as $placeholder) {
                    $placeholder->resolve();
                }
            }
            
            return yield $promise;
        });
        
        return $context->task($next($context, $request));
    }
    
    /**
     * Send multiple HTTP requests in parallel.
     * 
     * @param array $requests All HTTP requests (or request builders) to be sent.
     * @param int $concurrency Maximum concurrency level for parallel HTTP requests.
     * @return Pipeline HTTP response pipeline (unordered by default to provide maximum concurrency).
     * 
     * @throws \InvalidArgumentException If an invalid HTTP request (or builder) has been passed.
     */
    public function sendAll(array $requests, int $concurrency = 8): Pipeline
    {
        $input = [];
        
        foreach ($requests as $request) {
            if ($request instanceof RequestBuilder) {
                $request = $request->build();
            }
            
            if (!$request instanceof HttpRequest) {
                throw new \InvalidArgumentException('HttpRequest or RequestBuilder expected');
            }
            
            $input[] = $request;
        }
        
        $pipeline = new Pipeline(new IterableChannel($input), $concurrency);
        
        return $pipeline->unordered()->map(function (Context $context, HttpRequest $request) {
            return $this->send($context, $request);
        });
    }
    
    /**
     * Normalizes relative URIs by prepending them with the client's base URI.
     * 
     * @param Uri $uri URI to be normalized.
     * 
     * @throws \InvalidArgumentException When a relative request URI is given and no base URI is set.
     */
    protected function normalizeUri(Uri $uri): Uri
    {
        $path = $uri->getPath();
        
        if ($uri->getHost() !== '') {
            return $uri;
        }
        
        if ($this->baseUri === null) {
            throw new \InvalidArgumentException('Cannot use relative request URI without client base URI');
        }
        
        if ('/' == ($path[0] ?? null)) {
            $target = $this->baseUri->withPath($path);
        } elseif ($path === '') {
            $target = $this->baseUri;
        } else {
            $base = $this->baseUri->getPath();
            
            if ($base === '') {
                $target = $this->baseUri->withPath($path);
            } elseif (\substr($base, -1) == '/') {
                $target = $this->baseUri->withPath($base . $path);
            } else {
                $target = $this->baseUri->withPath(\preg_replace("'/[^/]+$'", '/', $base) . $path);
            }
        }
        
        return $target->withQuery($uri->getQuery())->withFragment($uri->getFragment());
    }

    /**
     * Establish a socket connection to the remote host.
     * 
     * @param Context $context Async execution context.
     * @param Uri $uri Uri of the remote peer.
     * @param array $protocols Available client-side ALPN protocols.
     */
    protected function connectSocket(Context $context, Uri $uri, array $protocols): \Generator
    {
        $tls = null;
        
        if ($uri->getScheme() == 'https') {
            $tls = new ClientEncryption();
            $tls = $tls->withPeerName($uri->getHostWithPort());
            
            if ($protocols) {
                $tls = $tls->withAlpnProtocols(...$protocols);
            }
        }
        
        $factory = new ClientFactory('tcp://' . $uri->getHostWithPort(true), $tls);
        $connect = (new Connect())->withTcpNodelay(true);
        
        return yield $factory->connect($context, $connect);
    }
    
    /**
     * Choose an HTTP connector based on negotiated ALPN protocol.
     * 
     * @param array $connectors Available HTTP connectors.
     * @param string $alpn Negotiated ALPN protocol.
     * 
     * @throws \RuntimeException When no connector can handle the negotiated ALPN protocol.
     */
    protected function chooseConnector(array $connectors, string $alpn): HttpConnector
    {
        foreach ($connectors as $connector) {
            if ($connector->isSupported($alpn)) {
                return $connector;
            }
        }
        
        throw new \RuntimeException(\sprintf('No connector supports ALPN protocol "%s"', $alpn));
    }
}
