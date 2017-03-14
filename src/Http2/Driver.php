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

use KoolKode\Async\Awaitable;
use KoolKode\Async\AwaitPending;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpDriver;
use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Http1\UpgradeHandler;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Http\Logger;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Implements the HTTP/2 protocol on the server side.
 *
 * @author Martin Schröder
 */
class Driver implements HttpDriver, UpgradeHandler, LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    protected $hpackContext;
    
    public function __construct(HPackContext $hpackContext = null)
    {
        $this->hpackContext = $hpackContext ?? HPackContext::createServerContext();
        $this->logger = new Logger(static::class);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 20;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getProtocols(): array
    {
        return [
            'h2'
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function handleConnection(HttpDriverContext $context, SocketStream $socket, callable $action): Awaitable
    {
        return new Coroutine(function () use ($context, $socket, $action) {
            $remotePeer = $socket->getRemoteAddress();
            
            $this->logger->debug('Accepted new HTTP/2 connection from {peer}', [
                'peer' => $remotePeer
            ]);
            
            $conn = new Connection($socket, new HPack($this->hpackContext));
            
            yield $conn->performServerHandshake();
            
            try {
                while (null !== ($received = yield $conn->nextRequest($context))) {
                    new Coroutine($this->processRequest($context, $conn, $action, ...$received), true);
                }
            } finally {
                try {
                    $conn->shutdown();
                } finally {
                    $this->logger->debug('Closed HTTP/2 connection to {peer}', [
                        'peer' => $remotePeer
                    ]);
                }
            }
        });
    }
    
    /**
     * {@inheritdoc}
     */
    public function isUpgradeSupported(string $protocol, HttpRequest $request): bool
    {
        if ($protocol === '') {
            return $this->isPrefaceRequest($request);
        }
        
        if ($request->getUri()->getScheme() === 'https') {
            return false;
        }
        
        if ($protocol !== 'h2c' || 1 !== $request->getHeader('HTTP2-Settings')) {
            return false;
        }
        
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function upgradeConnection(HttpDriverContext $context, SocketStream $socket, HttpRequest $request, callable $action): \Generator
    {
        if ($this->isPrefaceRequest($request)) {
            return yield from $this->upgradeConnectionDirect($context, $socket, $request, $action);
        }
        
        $settings = @\base64_decode($request->getHeaderLine('HTTP2-Settings'));
        
        if ($settings === false) {
            throw new StatusException(Http::CODE_BAD_REQUEST, 'HTTP/2 settings are not properly encoded');
        }
        
        // Discard request body before switching to HTTP/2.
        yield $request->getBody()->discard();
        
        $this->logger->info('{ip} "{method} {target} HTTP/{protocol}" {status} {size}', [
            'ip' => $request->getClientAddress(),
            'method' => $request->getMethod(),
            'target' => $request->getRequestTarget(),
            'protocol' => $request->getProtocolVersion(),
            'status' => Http::SWITCHING_PROTOCOLS,
            'size' => '-'
        ]);
        
        $buffer = Http::getStatusLine(Http::SWITCHING_PROTOCOLS, $request->getProtocolVersion()) . "\r\n";
        $buffer .= "Connection: upgrade\r\n";
        $buffer .= "Upgrade: h2c\r\n";
        
        yield $socket->write($buffer . "\r\n");
        
        $conn = new Connection($socket, new HPack($this->hpackContext));
        
        yield $conn->performServerHandshake(new Frame(Frame::SETTINGS, $settings));
        
        $this->logger->info('HTTP/{protocol} connection from {peer} upgraded to HTTP/2', [
            'protocol' => $request->getProtocolVersion(),
            'peer' => $socket->getRemoteAddress()
        ]);
        
        $remotePeer = $socket->getRemoteAddress();
        
        try {
            while (null !== ($received = yield $conn->nextRequest($context))) {
                new Coroutine($this->processRequest($conn, $action, ...$received), true);
            }
        } finally {
            try {
                $conn->shutdown();
            } finally {
                $this->logger->debug('Closed HTTP/2 connection to {peer}', [
                    'peer' => $remotePeer
                ]);
            }
        }
    }

    /**
     * Perform a direct upgrade of the connection to HTTP/2.
     * 
     * @param HttpDriverContext $context HTTP context related to the HTTP endpoint.
     * @param SocketStream $socket The underlying socket transport.
     * @param HttpRequest $request The HTTP request that caused the connection upgrade.
     * @param callable $action Server action to be performed for each incoming HTTP request.
     */
    protected function upgradeConnectionDirect(HttpDriverContext $context, SocketStream $socket, HttpRequest $request, callable $action): \Generator
    {
        $preface = yield $socket->readBuffer(\strlen(Connection::PREFACE_BODY), true);
        
        if ($preface !== Connection::PREFACE_BODY) {
            throw new StatusException(Http::BAD_REQUEST, 'Invalid HTTP/2 connection preface body');
        }
        
        $this->logger->info('{ip} "{method} {target} HTTP/{protocol}" {status} {size}', [
            'ip' => $request->getClientAddress(),
            'method' => $request->getMethod(),
            'target' => $request->getRequestTarget(),
            'protocol' => $request->getProtocolVersion(),
            'status' => Http::SWITCHING_PROTOCOLS,
            'size' => '-'
        ]);
        
        $conn = new Connection($socket, new HPack($this->hpackContext));
        
        yield $conn->performServerHandshake(null, true);
        
        $this->logger->info('HTTP/{protocol} connection from {peer} upgraded to HTTP/2', [
            'protocol' => $request->getProtocolVersion(),
            'peer' => $socket->getRemoteAddress()
        ]);
        
        $remotePeer = $socket->getRemoteAddress();
        
        try {
            while (null !== ($received = yield $conn->nextRequest($context))) {
                new Coroutine($this->processRequest($conn, $action, ...$received), true);
            }
        } finally {
            try {
                $conn->shutdown();
            } finally {
                $this->logger->debug('Closed HTTP/2 connection to {peer}', [
                    'peer' => $remotePeer
                ]);
            }
        }
    }
    
    /**
     * Check for a pre-parsed HTTP/2 connection preface.
     */
    protected function isPrefaceRequest(HttpRequest $request): bool
    {
        if ($request->getMethod() !== 'PRI') {
            return false;
        }
        
        if ($request->getRequestTarget() !== '*') {
            return false;
        }
        
        if ($request->getProtocolVersion() !== '2.0') {
            return false;
        }
        
        return true;
    }

    /**
     * Process the given HTTP request and generate and send an appropriate response to the client.
     */
    protected function processRequest(HttpDriverContext $context, Connection $conn, callable $action, Stream $stream, HttpRequest $request): \Generator
    {
        static $copy = [
            'Accept',
            'Accept-Charset',
            'Accept-Encoding',
            'Accept-Language',
            'Authorization',
            'Cookie',
            'Date',
            'DNT',
            'User-Agent',
            'Via'
        ];
        
        $next = new NextMiddleware($context->getMiddlewares(), function (HttpRequest $request) use ($context, $action) {
            $response = $action($request, $context);
            
            if ($response instanceof \Generator) {
                $response = yield from $response;
            }
            
            return $context->respond($request, $response);
        });
        
        $response = yield from $next($request);
        $response = $response->withHeader('Date', \gmdate(Http::DATE_RFC1123));
        
        $actions = [];
        $pushed = [];
        
        if ($response->hasHeader('Link') && $conn->getRemoteSetting(Connection::SETTING_ENABLE_PUSH)) {
            $links = [];
            
            $base = $request->getUri()->withQueryParams([])->withFragment('');
            $baseUri = (string) $base;
            
            foreach ($response->getHeaderTokens('Link') as $link) {
                if ($link->getParam('rel', '') !== 'preload' || $link->getParam('nopush', false)) {
                    $links[] = (string) $link;
                    
                    continue;
                }
                
                if (!\preg_match("'^<[^>]+>$'", $link->getValue())) {
                    $links[] = (string) $link;
                    
                    continue;
                }
                
                $url = \substr($link->getValue(), 1, -1);
                
                if (($url[0] ?? '') === '/') {
                    $uri = $base->withPath($url);
                } elseif (0 === \strpos($url, $baseUri)) {
                    try {
                        $uri = Uri::parse($uri);
                    } catch (\InvalidArgumentException $e) {
                        $this->logger->error('Malformed URL in HTTP push link: {message}', [
                            'message' => $e->getMessage(),
                            'exception' => $e
                        ]);
                        
                        $links[] = (string) $link;
                        
                        continue;
                    }
                } else {
                    $links[] = (string) $link;
                    
                    continue;
                }
                
                $resource = new HttpRequest($uri, Http::GET, [
                    'Accept' => '*/*',
                    'Cache-Control' => 'max-age=0',
                    'Referer' => (string) $request->getUri()
                ]);
                
                $resource = $resource->withAddress($request->getClientAddress(), ...$request->getProxyAddresses());
                
                foreach ($copy as $name) {
                    if ($request->hasHeader($name)) {
                        $resource = $resource->withHeader($name, ...$request->getHeader($name));
                    }
                }
                
                $push = $conn->openStream();
                
                $actions[] = new Coroutine($this->processRequest($context, $conn, $action, $push, $resource, false));
                
                $pushed[] = [
                    $resource,
                    $push->getId()
                ];
            }
            
            if ($pushed) {
                $response = $response->withHeader('Link', ...$links);
            }
        }
        
        \array_unshift($actions, $stream->sendResponse($request, $response, $pushed));
        
        yield new AwaitPending($actions);
    }
}
