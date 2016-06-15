<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpContext;
use KoolKode\Async\Http\HttpDriverInterface;
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\HttpUpgradeHandlerInterface;
use KoolKode\Async\Http\StreamBody;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Stream\BufferedDuplexStreamInterface;
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\Stream as IO;
use KoolKode\Async\Stream\StreamException;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\eventEmitter;

// TODO: Implement shutdown of HTTP2 server.

/**
 * HTTP/2 driver.
 * 
 * @author Martin Schröder
 */
class Http2Driver implements HttpDriverInterface, HttpUpgradeHandlerInterface
{
    protected $httpFactory;
    
    protected $sslOptions = [
        'reneg_limit' => 0,
        'reneg_limit_callback' => NULL
    ];
    
    protected $upgradeEnabled = true;
    
    /**
     * HTTP context being used.
     * 
     * @var HttpContext
     */
    protected $context;
    
    /**
     * PSR logger instance or NULL.
     * 
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * Open HTTP/2 connections.
     * 
     * @var array
     */
    protected $conns = [];
    
    public function __construct(HttpContext $context = NULL, LoggerInterface $logger = NULL)
    {
        $this->context = $context ?? new HttpContext();
        $this->logger = $logger;
    }
    
    public function setLogger(LoggerInterface $logger = NULL)
    {
        $this->logger = $logger;
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
    public function getSslOptions(): array
    {
        return $this->sslOptions;
    }
    
    /**
     * Enable connection upgrade using h2c protocol switching and direct upgrade via connection preface detection.
     * 
     * @param bool $enabled
     */
    public function setUpgradeEnabled(bool $enabled)
    {
        $this->upgradeEnabled = $enabled;
    }
    
    /**
     * Get the HTTP context being used.
     * 
     * @return HttpContext
     */
    public function getHttpContext(): HPackContext
    {
        return $this->context;
    }
    
    /**
     * Get HPACK header compression context.
     * 
     * @return HPackContext
     */
    public function getHPackContext(): HPackContext
    {
        return $this->context->getHpackContext();
    }
    
    /**
     * {@inheritdoc}
     */
    public function handleConnection(HttpEndpoint $endpoint, DuplexStreamInterface $socket, callable $action): \Generator
    {
        try {
            // Bail out if no connection preface is received.
            try {
                $this->conns[] = $conn = yield from Connection::connectServer($socket, $this->context, $this->logger);
            } catch (ConnectionException $e) {
                return;
            }
            
            $conn->getEvents()->observe(MessageReceivedEvent::class, function (MessageReceivedEvent $event) use($endpoint, $action) {
                yield from $this->handleMessage($event, $endpoint, $action);
            });
            
            while (true) {
                if (false === yield from $conn->handleNextFrame()) {
                    break;
                }
            }
        } catch (StreamException $e) {
            if ($this->logger) {
                $this->logger->debug('Dropped client due to socket error: {error} in {file} at line {line}', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        } finally {
            $socket->close();
        }
    }
    
    /**
     * Check for a pre-parsed HTTP/2 connection preface.
     * 
     * @param HttpRequest $request
     * @return bool
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
     * {@inheritdoc}
     */
    public function isUpgradeSupported(string $protocol, HttpRequest $request): bool
    {
        if (!$this->upgradeEnabled) {
            return false;
        }
        
        if ($protocol === '') {
            return $this->isPrefaceRequest($request);
        }
        
        if ($request->getUri()->getScheme() === 'https') {
            return false;
        }
        
        if ($protocol !== 'h2c') {
            return false;
        }
        
        $upgrade = array_map('strtolower', array_map('trim', implode(',', $request->getHeaderLine('Connection'))));
        if (!in_array('http2-settings', $upgrade)) {
            return false;
        }
        
        return count($request->getHeader('HTTP2-Settings')) === 1;
    }
    
    /**
     * {@inheritdoc}
     */
    public function upgradeConnection(BufferedDuplexStreamInterface $socket, HttpRequest $request, HttpResponse $response, HttpEndpoint $endpoint, callable $action): \Generator
    {
        if ($this->isPrefaceRequest($request)) {
            return yield from $this->upgradeConnectionDirect($socket, $request, $response, $endpoint, $action);
        }
        
        $settings = @base64_decode($request->getHeaderLine('HTTP2-Settings'));
        if ($settings === false) {
            return $response->withStatus(Http::CODE_BAD_REQUEST, 'HTTP/2 settings are not properly encoded');
        }
        
        try {
            $message = sprintf("HTTP/%s 101 Switching Protocols\r\n", $request->getProtocolVersion());
            $message .= "Connection: Upgrade\r\n";
            $message .= "Upgrade: h2c\r\n";
            $message .= "\r\n";
            
            yield from $socket->write($message);
            
            $conn = new Connection(Connection::MODE_SERVER, $socket, $this->context, yield eventEmitter(), $this->logger);
            
            $preface = yield from IO::readBuffer($socket, strlen(Connection::PREFACE), true);
            
            if ($preface !== Connection::PREFACE) {
                if ($this->logger) {
                    $this->logger->warning('Client did no send valid HTTP/2 connection preface');
                }
                
                return;
            }
            
            $conn->getEvents()->observe(MessageReceivedEvent::class, function (MessageReceivedEvent $event) use ($endpoint, $action) {
                yield from $this->handleMessage($event, $endpoint, $action);
            });
            
            yield from $conn->handleServerHandshake(new Frame(Frame::SETTINGS, $settings));
            
            if ($this->logger) {
                $this->logger->debug('HTTP/{version} connection upgraded to HTTP/2', [
                    'version' => $request->getProtocolVersion()
                ]);
            }
            
            while (true) {
                if (false === yield from $conn->handleNextFrame()) {
                    break;
                }
            }
        } finally {
            $socket->close();
        }
    }
    
    /**
     * Upgrade connection by reading HTTP/2 connection preface body and switching to HTTP/2 connection.
     * 
     * @param BufferedDuplexStreamInterface $socket
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @param HttpEndpoint $endpoint
     * @param callable $action
     */
    protected function upgradeConnectionDirect(BufferedDuplexStreamInterface $socket, HttpRequest $request, HttpResponse $response, HttpEndpoint $endpoint, callable $action)
    {
        try {
            $preface = yield from IO::readBuffer($socket, strlen(Connection::PREFACE_BODY), true);
            
            if ($preface !== Connection::PREFACE_BODY) {
                if ($this->logger) {
                    $this->logger->warning('Client did no send valid HTTP/2 connection preface');
                }
                
                return;
            }
            
            $conn = new Connection(Connection::MODE_SERVER, $socket, $this->context, yield eventEmitter(), $this->logger);
            
            $conn->getEvents()->observe(MessageReceivedEvent::class, function (MessageReceivedEvent $event) use ($endpoint, $action) {
                yield from $this->handleMessage($event, $endpoint, $action);
            });
            
            yield from $conn->handleServerHandshake();
            
            if ($this->logger) {
                $this->logger->debug('HTTP/{version} connection upgraded to HTTP/2', [
                    'version' => $request->getProtocolVersion()
                ]);
            }
            
            while (true) {
                if (false === yield from $conn->handleNextFrame()) {
                    break;
                }
            }
        } finally {
            $socket->close();
        }
    }
    
    /**
     * Handle an incoming HTTP/2 request.
     * 
     * @param MessageReceivedEvent $event
     * @param HttpEndpoint $endpoint
     * @param callable $action
     */
    public function handleMessage(MessageReceivedEvent $event, HttpEndpoint $endpoint, callable $action): \Generator
    {
        $event->consume();
        
        $authority = $event->getHeaderValue(':authority');
        $path = ltrim($event->getHeaderValue(':path'), '/');
        
        $uri = Uri::parse(sprintf('%s://%s/%s', $endpoint->isEncrypted() ? 'https' : 'http', $authority, $path));
        
        $request = new HttpRequest($uri, $event->getHeaderValue(':method'), [], '2.0');
        $request = $request->withBody(new StreamBody($event->body));
        
        foreach ($event->headers as $header) {
            if ($header[0][0] !== ':') {
                $request = $request->withAddedHeader($header[0], $header[1]);
            }
        }
    
        if ($this->logger) {
            $this->logger->debug('>> {method} {target} HTTP/{version}', [
                'method' => $request->getMethod(),
                'target' => $request->getRequestTarget(),
                'version' => $request->getProtocolVersion()
            ]);
        }
        
        $response = new HttpResponse(Http::CODE_OK, [], '2.0');
        
        $response = $action($request, $response);
        
        if ($response instanceof \Generator) {
            $response = yield from $response;
        }
        
        if (!$response instanceof HttpResponse) {
            throw new \RuntimeException(sprintf('Action must return an HTTP response, actual value is %s', is_object($response) ? get_class($response) : gettype($response)));
        }
        
        $response = $response->withProtocolVersion('2.0');
        
        try {
            yield from $event->stream->sendResponse($request, $response, $event->started);
        } catch (StreamException $e) {
            if ($this->logger) {
                $this->logger->debug('Dropped client connection due to socket error: {error} in {file} at line {line}', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        }
    }
}
