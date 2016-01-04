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

use KoolKode\Async\Stream\SocketStream;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\awaitRead;
use function KoolKode\Async\runTask;

/**
 * FastCGI server endpoint.
 * 
 * @author Martin Schröder
 */
class FcgiEndpoint
{    
    /**
     * PHP file descriptor being used to talk FCGI over a UNIX domain socket.
     *
     * @var int
     */
    const FCGI_LISTENSOCK_FILENO = 0;
    
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
     * Should be used as task title when starting the FCGI endpoint.
     * 
     * @return string
     */
    public function getTitle(): string
    {
        if ($this->port) {
            return sprintf('FCGI Endpoint (TCP port %u)', $this->port);
        }
        
        return 'FCGI Endpoint (FCGI_LISTENSOCK_FILENO)';
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
        if ($this->port) {
            $errno = NULL;
            $errstr = NULL;
            $address = sprintf('%s:%u', $this->address, $this->port);
            
            $server = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
            
            if ($server === false) {
                throw new \RuntimeException(sprintf('Unable to create TCP-based FCGI responder: %s', $address));
            }
            
            try {
                stream_set_blocking($server, 0);
                
                if ($this->logger) {
                    $this->logger->info('Started FCGI endpoint: {address}:{port}', [
                        'address' => $this->address,
                        'port' => $this->port
                    ]);
                }
                
                while (true) {
                    yield awaitRead($server);
                    
                    $socket = @stream_socket_accept($server, 0);
                    
                    if ($socket !== false) {
                        stream_set_blocking($socket, 0);
                        
                        $stream = new SocketStream($socket);
                        
                        if ($this->logger) {
                            $this->logger->debug('Accepted FCGI connection from {peer}', [
                                'peer' => stream_socket_get_name($socket, true)
                            ]);
                        }
                        
                        $handler = new ConnectionHandler($stream, $this->logger);
                        
                        yield runTask($handler->run($action), 'FCGI TCP Connection Handler');
                    }
                }
            } finally {
                @fclose($server);
            }
        } else {
            $stream = yield from SocketStream::open(sprintf('php://fd/%u', self::FCGI_LISTENSOCK_FILENO), 'rb');
            $handler = new ConnectionHandler($stream);
            
            yield from $handler->run($action);
        }
    }
}
