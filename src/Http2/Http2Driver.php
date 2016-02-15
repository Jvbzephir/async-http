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
use KoolKode\Async\Http\HttpDriverInterface;
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\HttpUpgradeHandlerInterface;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\InputStreamInterface;
use KoolKode\Async\Stream\Stream as IO;
use KoolKode\Async\Stream\StreamException;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\eventEmitter;

/**
 * HTTP/2 driver.
 * 
 * @author Martin Schröder
 */
class Http2Driver implements HttpDriverInterface, HttpUpgradeHandlerInterface
{
    public function __construct(LoggerInterface $logger = NULL)
    {
        $this->logger = $logger;
    }
    
    protected $httpFactory;
    
    protected $sslOptions = [
        'reneg_limit' => 0,
        'reneg_limit_callback' => NULL
    ];
    
    protected $upgradeEnabled = false;
    
    protected $logger;
    
    /**
     * Open HTTP/2 connections.
     * 
     * @var array
     */
    protected $conns = [];
    
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
     * {@inheritdoc}
     */
    public function handleConnection(HttpEndpoint $endpoint, DuplexStreamInterface $socket, callable $action): \Generator
    {
        try {
            $this->conns[] = $conn = yield from Connection::connectServer($socket, $this->logger);
            
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
     * {@inheritdoc}
     */
    public function isDirectUpgradeSupported(HttpEndpoint $endpoint, InputStreamInterface $stream): \Generator
    {
        if (!$this->upgradeEnabled) {
            return false;
        }
        
        if ($endpoint->isEncrypted()) {
            return false;
        }
        
        return Connection::PREFACE === yield from IO::readBuffer($stream, strlen(Connection::PREFACE));
    }
    
    /**
     * {@inheritdoc}
     */
    public function isUpgradeSupported(string $protocol, HttpRequest $request): bool
    {
        if (!$this->upgradeEnabled) {
            return false;
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
    public function upgradeDirectConnection(HttpEndpoint $endpoint, DuplexStreamInterface $socket, callable $action): \Generator
    {
        return yield from $this->handleConnection($endpoint, $socket, $action);
    }
    
    /**
     * {@inheritdoc}
     */
    public function upgradeConnection(HttpRequest $request, HttpResponse $response, HttpEndpoint $endpoint, DuplexStreamInterface $socket, callable $action): \Generator
    {
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
            
            $conn = new Connection(Connection::MODE_SERVER, $socket, yield eventEmitter(), $this->logger);
            
            $preface = yield from IO::readBuffer($socket, strlen(Connection::PREFACE));
            
            if ($preface !== self::PREFACE) {
                if ($this->logger) {
                    $this->logger->warning('Client did no send valid HTTP/2 connection preface');
                }
                
                return;
            }
            
            $conn->getEvents()->observe(MessageReceivedEvent::class, function (MessageReceivedEvent $event) use ($endpoint, $action) {
                yield from $this->handleMessage($event, $endpoint, $action);
            });
            
            yield from $conn->handleFrame(new Frame(Frame::SETTINGS, $settings));
            
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
    
    public function handleMessage(MessageReceivedEvent $event, HttpEndpoint $endpoint, callable $action): \Generator
    {
        $authority = $event->getHeaderValue(':authority');
        $path = ltrim($event->getHeaderValue(':path'), '/');
        
        $uri = Uri::parse(sprintf('%s://%s/%s', $endpoint->isEncrypted() ? 'https' : 'http', $authority, $path));
        
        $request = new HttpRequest($uri, $event->body, $event->getHeaderValue(':method'));
        $request = $request->withProtocolVersion('2.0');
    
        foreach ($event->headers as $header) {
            $request = $request->withAddedHeader($header[0], $header[1]);
        }
    
        if ($this->logger) {
            $this->logger->debug('>> {method} {target} HTTP/{version}', [
                'method' => $request->getMethod(),
                'target' => $request->getRequestTarget(),
                'version' => $request->getProtocolVersion()
            ]);
        }
        
        $response = new HttpResponse(Http::CODE_OK, yield from IO::temp());
        $response = $response->withProtocolVersion('2.0');
        
        $response = $action($request, $response);
        
        if ($response instanceof \Generator) {
            $response = yield from $response;
        }
        
        if (!$response instanceof HttpResponse) {
            throw new \RuntimeException(sprintf('Action must return an HTTP response, actual value is %s', is_object($response) ? get_class($response) : gettype($response)));
        }
        
        $response = $response->withProtocolVersion('2.0');
    
        try {
            yield from $event->stream->sendResponse($response, $event->started);
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
