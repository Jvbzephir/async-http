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
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpDriver;
use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Http1\UpgradeHandler;
use KoolKode\Async\Http\Middleware\NextMiddleware;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerInterface;

/**
 * Implements the HTTP/2 protocol on the server side.
 *
 * @author Martin Schröder
 */
class Driver implements HttpDriver, UpgradeHandler
{
    protected $hpackContext;
    
    protected $logger;
    
    public function __construct(HPackContext $hpackContext = null, LoggerInterface $logger = null)
    {
        $this->hpackContext = $hpackContext ?? HPackContext::createServerContext();
        $this->logger = $logger;
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
            
            if ($this->logger) {
                $this->logger->debug('Accepted new HTTP/2 connection from {peer}', [
                    'peer' => $remotePeer
                ]);
            }
            
            $conn = new Connection($socket, new HPack($this->hpackContext), $this->logger);
            
            yield $conn->performServerHandshake();
            
            try {
                while (null !== ($received = yield $conn->nextRequest($context))) {
                    new Coroutine($this->processRequest($context, $conn, $action, ...$received), true);
                }
            } finally {
                try {
                    $conn->shutdown();
                } finally {
                    if ($this->logger) {
                        $this->logger->debug('Closed HTTP/2 connection to {peer}', [
                            'peer' => $remotePeer
                        ]);
                    }
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
        
        if ($this->logger) {
            $this->logger->info('HTTP/{protocol} {status} {reason}', [
                'protocol' => $request->getProtocolVersion(),
                'status' => Http::SWITCHING_PROTOCOLS,
                'reason' => \trim(Http::getReason(Http::SWITCHING_PROTOCOLS))
            ]);
        }
        
        $buffer = Http::getStatusLine(Http::SWITCHING_PROTOCOLS, $request->getProtocolVersion()) . "\r\n";
        $buffer .= "Connection: upgrade\r\n";
        $buffer .= "Upgrade: h2c\r\n";
        
        yield $socket->write($buffer . "\r\n");
        
        $conn = new Connection($socket, new HPack($this->hpackContext), $this->logger);
        
        yield $conn->performServerHandshake(new Frame(Frame::SETTINGS, $settings));
        
        if ($this->logger) {
            $this->logger->info('HTTP/{protocol} connection from {peer} upgraded to HTTP/2', [
                'protocol' => $request->getProtocolVersion(),
                'peer' => $socket->getRemoteAddress()
            ]);
        }
        
        $remotePeer = $socket->getRemoteAddress();
        
        try {
            while (null !== ($received = yield $conn->nextRequest($context))) {
                new Coroutine($this->processRequest($conn, $action, ...$received), true);
            }
        } finally {
            try {
                $conn->shutdown();
            } finally {
                if ($this->logger) {
                    $this->logger->debug('Closed HTTP/2 connection to {peer}', [
                        'peer' => $remotePeer
                    ]);
                }
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
        
        if ($this->logger) {
            $this->logger->info('HTTP/{protocol} {status} {reason}', [
                'protocol' => $request->getProtocolVersion(),
                'status' => Http::SWITCHING_PROTOCOLS,
                'reason' => \trim(Http::getReason(Http::SWITCHING_PROTOCOLS))
            ]);
        }
        
        $conn = new Connection($socket, new HPack($this->hpackContext), $this->logger);
        
        yield $conn->performServerHandshake(null, true);
        
        if ($this->logger) {
            $this->logger->info('HTTP/{protocol} connection from {peer} upgraded to HTTP/2', [
                'protocol' => $request->getProtocolVersion(),
                'peer' => $socket->getRemoteAddress()
            ]);
        }
        
        $remotePeer = $socket->getRemoteAddress();
        
        try {
            while (null !== ($received = yield $conn->nextRequest($context))) {
                new Coroutine($this->processRequest($conn, $action, ...$received), true);
            }
        } finally {
            try {
                $conn->shutdown();
            } finally {
                if ($this->logger) {
                    $this->logger->debug('Closed HTTP/2 connection to {peer}', [
                        'peer' => $remotePeer
                    ]);
                }
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
        if ($this->logger) {
            $this->logger->info('{method} {target} HTTP/{protocol}', [
                'method' => $request->getMethod(),
                'target' => $request->getRequestTarget(),
                'protocol' => $request->getProtocolVersion()
            ]);
        }
        
        $next = new NextMiddleware($context->getMiddlewares(), $action);
        
        $response = yield from $next($request);
        
        if (!$response instanceof HttpResponse) {
            if ($this->logger) {
                $type = \is_object($response) ? \get_class($response) : \gettype($response);
                
                $this->logger->error('Expecting HTTP response, server action returned {type}', [
                    'type' => $type
                ]);
            }
            
            $response = new HttpResponse(Http::INTERNAL_SERVER_ERROR);
        }
        
        $response = $response->withProtocolVersion($request->getProtocolVersion());
        $response = $response->withHeader('Date', \gmdate(Http::DATE_RFC1123));
        
        if ($this->logger) {
            $reason = \trim($response->getReasonPhrase());
            
            if ($reason === '') {
                $reason = \trim(Http::getReason($response->getStatusCode()));
            }
            
            $this->logger->info('HTTP/{protocol} {status} {reason}', [
                'protocol' => $response->getProtocolVersion(),
                'status' => $response->getStatusCode(),
                'reason' => $reason
            ]);
        }
        
        yield $stream->sendResponse($request, $response);
    }
}
