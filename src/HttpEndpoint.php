<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// TODO: DoS prevention using sth. like this: https://hacks.mozilla.org/2013/01/building-a-node-js-server-that-wont-melt-a-node-js-holiday-season-part-5/

namespace KoolKode\Async\Http;

use KoolKode\Async\Http\Http1\Http1Driver;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketServerFactory;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\currentTask;

/**
 * HTTP server endpoint that provides at least HTTP/1 capabilities.
 * 
 * Supports multiple HTTP versions and the HTTP upgrade mechanism.
 * 
 * @author Martin Schröder
 */
class HttpEndpoint
{
    /**
     * Server socket factory.
     * 
     * @var SocketServerFactory
     */
    protected $socketFactory;
    
    /**
     * Registered HTTP drivers.
     * 
     * @var array
     */
    protected $drivers = [];
    
    /**
     * Registered HTTP/1.1 upgrade handlers.
     * 
     * @var array
     */
    protected $upgradeHandlers = [];
    
    /**
     * HTTP/1 driver is the basic requirement.
     * 
     * @var Http1Driver
     */
    protected $http1Driver;
    
    /**
     * Optional logger.
     * 
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * Create a new HTTP server endpoint.
     * 
     * @param int $port
     * @param string $address
     * @param string $peerName
     */
    public function __construct(int $port, string $address = '0.0.0.0', string $peerName = NULL)
    {
        $this->http1Driver = new Http1Driver();
        $this->socketFactory = new SocketServerFactory($address, $port);
        
        if ($peerName !== NULL) {
            $this->socketFactory->setPeerName($peerName);
        }
    }
    
    public function setLogger(LoggerInterface $logger = NULL)
    {
        $this->logger = $logger;
    }
    
    public function getPort(): int
    {
        return $this->socketFactory->getPort();
    }
    
    public function getPeerName(): string
    {
        return $this->socketFactory->getOption('ssl', 'peer_name');
    }
    
    public function isEncrypted(): bool
    {
        return $this->socketFactory->isEncrypted();
    }
    
    public function setCertificate(string $file, bool $allowSelfSigned = false)
    {
        $this->socketFactory->setCertificate($file, $allowSelfSigned);
    }
    
    /**
     * Driver will also be added as upgrade handler if it implements the upgrade handler interface.
     * 
     * @param HttpDriverInterface $driver
     */
    public function addDriver(HttpDriverInterface $driver)
    {
        $this->drivers[] = $driver;
        
        if ($driver instanceof HttpUpgradeHandlerInterface) {
            $this->addUpgradeHandler($driver);
        }
    }

    /**
     * Get the HTTP/1 driver backing the endpoint.
     * 
     * @return Http1Driver
     */
    public function getHttp1Driver(): Http1Driver
    {
        return $this->http1Driver;
    }
    
    /**
     * Register an HTTP/1.1 upgrade handler.
     * 
     * @param HttpUpgradeHandlerInterface $handler
     */
    public function addUpgradeHandler(HttpUpgradeHandlerInterface $handler)
    {
        $this->upgradeHandlers[] = $handler;
    }
    
    /**
     * Generate a title that can be used as title of the server task.
     * 
     * @return string
     */
    public function getTitle(): string
    {
        return sprintf('HTTP endpoint on %s:%u', $this->address, $this->port);
    }
    
    /**
     * Coroutine that creates a server socket and accepts client connections.
     * 
     * @param callable $action
     * @return \Generator
     * 
     * @throws \RuntimeException
     */
    public function run(callable $action): \Generator
    {
        $alpn = [];
        foreach (array_merge($this->drivers, [
            $this->http1Driver
        ]) as $driver) {
            $alpn = array_merge($alpn, $driver->getProtocols());
        }
        
        if (!empty($alpn) && Socket::isAlpnSupported()) {
            $this->socketFactory->setOption('ssl', 'alpn_protocols', implode(',', array_unique($alpn)));
        }
        
        (yield currentTask())->setAutoShutdown(true);
        
        $server = $this->socketFactory->createServer();
        
        try {
            yield from $server->listen(function (SocketStream $client) use ($action) {
                yield from $this->handleClient($client, $action);
            });
        } finally {
            $server->shutdown();
        }
    }
    
    /**
     * Handle a new connection initiated by a client.
     * 
     * @param SocketStream $stream
     * @param callable $action
     */
    protected function handleClient(SocketStream $stream, callable $action): \Generator
    {
        try {
            // Driver selection is based on negotiated TLS ALPN protocol.
            $crypto = (array) ($stream->getMetadata()['crypto'] ?? []);
            
            if (isset($crypto['alpn_protocol'])) {
                foreach ($this->drivers as $driver) {
                    if (in_array($crypto['alpn_protocol'], $driver->getProtocols(), true)) {
                        return yield from $driver->handleConnection($this, $stream, $action);
                    }
                }
            }
            
            yield from $this->http1Driver->handleConnection($this, $stream, $action);
        } finally {
            $stream->close();
        }
    }
    
    /**
     * Find an HTTP upgrade handler that can upgrade the connection to the requested protocl.
     * 
     * @param string $protocol
     * @param HttpRequest $request
     * @return HttpUpgradeHandlerInterface or NULL when no such handler was found.
     */
    public function findUpgradeHandler(string $protocol, HttpRequest $request)
    {
        foreach ($this->upgradeHandlers as $handler) {
            if ($handler->isUpgradeSupported($protocol, $request)) {
                return $handler;
            }
        }
    }
    
    /**
     * Create a suitable stream context for the server socket.
     * 
     * @param string $host
     * @return resource
     */
    protected function createStreamContext(string $host)
    {
        $sslOptions = $this->sslOptions;
        foreach (array_merge([
            $this->http1Driver
        ], $this->drivers) as $driver) {
            $sslOptions = array_merge($sslOptions, $driver->getSslOptions());
        }
        
        $alpn = [];
        foreach (array_merge($this->drivers, [
            $this->http1Driver
        ]) as $driver) {
            $alpn = array_merge($alpn, $driver->getProtocols());
        }
        
        if (!empty($alpn) && Socket::isAlpnSupported()) {
            $sslOptions['alpn_protocols'] = implode(',', array_unique($alpn));
        }
        
        $context = [
            'socket' => array_merge($this->socketOptions, [
                'bindto' => $host
            ]),
            'ssl' => $sslOptions
        ];
        
        return stream_context_create($context);
    }
}
