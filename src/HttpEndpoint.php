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
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\StreamException;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\awaitRead;
use function KoolKode\Async\captureError;
use function KoolKode\Async\currentTask;
use function KoolKode\Async\runTask;

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
     * Server socket port.
     * 
     * @var int
     */
    protected $port;

    /**
     * Server socket IP address.
     * 
     * @var string
     */
    protected $address;

    /**
     * Default socket context options.
     * 
     * @var array
     */
    protected $socketOptions = [
        'ipv6_v6only' => false,
        'backlog' => 128
    ];
    
    /**
     * Default SSL context options.
     * 
     * @var array
     */
    protected $sslOptions = [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => false,
        'verify_depth' => 10,
        'disable_compression' => true,
        'SNI_enabled' => true,
        'single_ecdh_use' => false,
        'ecdh_curve' => 'prime256v1',
        'ciphers' => 'HIGH:!SSLv2:!SSLv3',
        'reneg_limit' => 0
    ];
    
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
        $this->port = $port;
        $this->address = (strpos($address, ':') === false) ? $address : '[' . trim($address, '][') . ']';
        $this->sslOptions['peer_name'] = $peerName ?? gethostbyname(gethostname());
        
        $this->http1Driver = new Http1Driver();
    }
    
    public function getPeerName(): string
    {
        return $this->sslOptions['peer_name'];
    }
    
    public function getPort(): int
    {
        return $this->port;
    }
    
    public function isEncrypted(): bool
    {
        return isset($this->sslOptions['local_cert']) && trim($this->sslOptions['local_cert']) !== '';
    }
    
    public function setCertificate(string $file, bool $allowSelfSigned = false)
    {
        $this->sslOptions['local_cert'] = $file;
        $this->sslOptions['allow_self_signed'] = $allowSelfSigned;
    }
    
    /**
     * Set allowed OpenSSL ciphers.
     */
    public function setCiphers(string $ciphers)
    {
        $this->sslOptions['ciphers'] = $ciphers;
    }

    public function setPassphrase(string $password = NULL)
    {
        if ($password === NULL) {
            unset($this->sslOptions['passphrase']);
        } else {
            $this->sslOptions['passphrase'] = $password;
        }
    }
    
    public function setLogger(LoggerInterface $logger = NULL)
    {
        $this->logger = $logger;
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
        $host = sprintf('%s:%u', $this->address, $this->port);
        $context = $this->createStreamContext($host);
        
        $errno = NULL;
        $errstr = NULL;
        
        $server = @stream_socket_server(sprintf('tcp://%s', $host), $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        
        if ($server === false) {
            throw new \RuntimeException(sprintf('Unable to start HTTP endpoint "%s"', $host));
        }
        
        try {
            stream_set_blocking($server, 0);
            
            if ($this->logger) {
                $this->logger->info('Started HTTP endpoint {address}:{port}', [
                    'address' => $this->address,
                    'port' => $this->port
                ]);
            }
            
            (yield currentTask())->setAutoShutdown(true);
            
            while (true) {
                yield awaitRead($server);
                
                $socket = @stream_socket_accept($server, 0);
                
                if ($socket !== false) {
                    stream_set_blocking($socket, 0);
                    stream_set_read_buffer($socket, 0);
                    stream_set_write_buffer($socket, 0);
                    
                    $stream = new SocketStream($socket);
                    
                    if ($this->logger) {
                        $this->logger->debug('Accepted HTTP client connection from {peer}', [
                            'peer' => stream_socket_get_name($socket, true)
                        ]);
                    }
                    
                    yield runTask($this->handleClient($stream, $action));
                }
            }
        } finally {
            @fclose($server);
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
            if (isset($this->sslOptions['local_cert'])) {
                try {
                    yield from $stream->encrypt(true);
                } catch (StreamException $e) {
                    if ($this->logger) {
                        $this->logger->debug('Dropped client {peer} due to TLS handshake failure: {error}', [
                            'peer' => $stream->getPeer(),
                            'error' => $e->getMessage()
                        ]);
                    }
                    
                    return;
                }
                
                // Driver selection is based on negotiated TLS ALPN protocol.
                $crypto = (array)$stream->getMetadata()['crypto'];
                
                if ((isset($crypto['alpn_protocol']))) {
                    foreach ($this->drivers as $driver) {
                        if (in_array($crypto['alpn_protocol'], $driver->getProtocols(), true)) {
                            yield runTask($this->handleConnection($driver, $stream, $action), 'HTTP Connection Handler');
                            
                            return;
                        }
                    }
                }
            }
            
            yield runTask($this->handleConnection($this->http1Driver, $stream, $action), 'HTTP Connection Handler');
        } catch (\Throwable $e) {
            $stream->close();
            
            yield captureError($e);
        }
    }
    
    protected function handleConnection(HttpDriverInterface $driver, SocketStream $stream, callable $action)
    {
        return yield from $driver->handleConnection($this, $stream, $action);
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
        
        $context = stream_context_create($context);
        
        return $context;
    }
}
