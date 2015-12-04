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

use KoolKode\Async\Http\HttpDriverInterface;
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\HttpUpgradeHandlerInterface;
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\InputStreamInterface;
use KoolKode\Async\SystemCall;
use KoolKode\K1\Http\DefaultHttpFactory;
use KoolKode\K1\Http\Http;
use KoolKode\K1\Http\HttpFactoryInterface;
use KoolKode\Stream\ResourceInputStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
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
    
    public function getHttpFactory(): HttpFactoryInterface
    {
        if ($this->httpFactory === NULL) {
            $this->httpFactory = new DefaultHttpFactory();
        }
        
        return $this->httpFactory;
    }
    
    public function setHttpFactory(HttpFactoryInterface $factory)
    {
        $this->httpFactory = $factory;
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
        
        return Connection::PREFACE === yield from $stream->read(strlen(Connection::PREFACE), true);
    }
    
    /**
     * {@inheritdoc}
     */
    public function isUpgradeSupported(string $protocol, ServerRequestInterface $request): bool
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
    public function upgradeConnection(ServerRequestInterface $request, ResponseInterface $response, HttpEndpoint $endpoint, DuplexStreamInterface $socket, callable $action): \Generator
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
            
            $conn = new Connection(Connection::MODE_SERVER, $socket, yield SystemCall::newEventEmitter(), $this->logger);
            
            $preface = yield from $socket->read(strlen(Connection::PREFACE), true);
            
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
        $factory = $this->getHttpFactory();
        
        $authority = $event->getHeaderValue(':authority');
        $path = ltrim($event->getHeaderValue(':path'), '/');
    
        $uri = $factory->createUri(sprintf('%s://%s/%s', $endpoint->isEncrypted() ? 'https' : 'http', $authority, $path));
    
        $server = [
            'SERVER_PROTOCOL' => 'HTTP/2.0',
            'REQUEST_METHOD' => $event->getHeaderValue(':method'),
            'REQUEST_URI' => '/' . $path,
            'SCRIPT_NAME' => '',
            'SERVER_NAME' => $endpoint->getPeerName(),
            'SERVER_PORT' => $endpoint->getPort(),
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
            'HTTP_HOST' => $uri->getHost() ?? $this->getPeerName()
        ];
    
        $query = [];
        parse_str($uri->getQuery(), $query);
    
        yield;
    
        $request = $factory->createServerRequest($server, $query);
        $request = $request->withProtocolVersion('2.0');
        $request = $request->withUri($uri);
    
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
    
        $buffer = yield SystemCall::createTempStream();
    
        while (!$event->body->eof()) {
            yield from $buffer->write(yield from $event->body->read());
        }
    
        $buffer = $buffer->detach();
        rewind($buffer);
        stream_set_blocking($buffer, 1);
    
        $request = $request->withBody(new ResourceInputStream($buffer));
    
        //         $push = [];
        $response = $action($request, $factory->createResponse());
    
        if (is_array($response)) {
            //             $push = $response[1];
            $response = $response[0];
        }
    
        yield from $event->stream->sendResponse($response);
    }
}
