<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Fcgi;

use KoolKode\Async\Http\HttpContext;
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\awaitRead;
use function KoolKode\Async\currentTask;
use function KoolKode\Async\runTask;

/**
 * FastCGI server endpoint (responder role in FCGI terms).
 * 
 * @author Martin Schröder
 */
class FcgiEndpoint
{
    /**
     * TCP port to be used, a value of 0 will use a UNIX domain socket instead.
     * 
     * @var int
     */
    protected $port;
    
    /**
     * Local network address for a TCP server.
     * 
     * @var string
     */
    protected $address;
    
    /**
     * TCP backlog size.
     * 
     * @var int
     */
    protected $backlogSize = 128;
    
    /**
     * HTTP context.
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
     * Create a new FastCGI endpoint.
     * 
     * @param int $port TCP port to be used, a value of 0 will use a UNIX domain socket instead.
     * @param string $address Local network address for a TCP server.
     * @param LoggerInterface $logger PSR logger instance.
     */
    public function __construct(int $port = 0, string $address = '0.0.0.0', LoggerInterface $logger = NULL)
    {
        $this->port = $port;
        $this->address = (strpos($address, ':') === false) ? $address : '[' . trim($address, '][') . ']';
        $this->logger = $logger;
    }
    
    /**
     * Set the desired TCP backlog size.
     * 
     * @param int $size
     */
    public function setBacklogSize(int $size)
    {
        $this->backlogSize = $size;
    }
    
    /**
     * Get the HTTP context being used by the FCGI endpoint.
     */
    public function getHttpContext(): HttpContext
    {
        return $this->context;
    }
    
    /**
     * Should be used as task title when starting the FCGI endpoint.
     * 
     * @return string
     */
    public function getTitle(): string
    {
        return 'FCGI Endpoint: ' . ($this->port ? sprintf('tcp://%s:%u', $this->address, $this->port) : sprintf('unix://%s', $this->address));
    }
    
    /**
     * Coroutine that starts the FCGI server and handles incoming requests.
     * 
     * @param callable $action
     * 
     * @throws \RuntimeException When the server endpoint could not be started.
     */
    public function run(callable $action): \Generator
    {
        $errno = NULL;
        $errstr = NULL;
        
        if ($this->port == 0) {
            if (!Socket::isUnixSocketSupported()) {
                throw new \RuntimeException('Unix domain sockets are not available');
            }
            
            $address = sprintf('unix://%s', $this->address);
        } else {
            $address = sprintf('tcp://%s:%u', $this->address, $this->port);
        }
        
        $context = stream_context_create([
            'socket' => [
                'backlog' => $this->backlogSize
            ]
        ]);
        
        $server = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        
        if ($server === false) {
            throw new \RuntimeException(sprintf('Unable to create FCGI responder server socket %s: [%s] %s', $address, $errno, $errstr));
        }
        
        try {
            stream_set_blocking($server, 0);
            
            if ($this->logger) {
                $this->logger->info('Started FCGI endpoint: {address}', [
                    'address' => $address
                ]);
            }
            
            // Enable auto-shutdown for FCGI server task.
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
                        $this->logger->debug('Accepted FCGI connection from {peer}', [
                            'peer' => stream_socket_get_name($socket, true)
                        ]);
                    }
                    
                    $handler = new ConnectionHandler($stream, $this->context, $this->logger);
                    
                    yield runTask($handler->run($action), 'FCGI Connection Handler');
                }
            }
        } finally {
            @fclose($server);
        }
    }
}
