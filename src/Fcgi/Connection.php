<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Fcgi;

use KoolKode\Async\Awaitable;
use KoolKode\Async\Coroutine;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpDriverContext;
use KoolKode\Async\Log\LoggerProxy;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Success;
use KoolKode\Async\Util\Channel;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * FastCGI connection implementation.
 * 
 * @author Martin Schröder
 */
class Connection implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    /**
     * Flag that indicates a connection should not be closed after serving a request.
     *
     * @var int
     */
    const FCGI_KEEP_CONNECTION = 1;

    /**
     * A Responder FastCGI application has the same purpose as a CGI/1.1 program:
     * It receives all the information associated with an HTTP request and generates an HTTP response.
     *
     * @var int
     */
    const FCGI_RESPONDER = 1;

    /**
     * An Authorizer FastCGI application receives all the information associated with an HTTP request and generates an authorized/unauthorized decision.
     *
     * In case of an authorized decision the Authorizer can also associate name-value pairs with the HTTP request; when giving an unauthorized
     * decision the Authorizer sends a complete response to the HTTP client.
     *
     * @var int
     */
    const FCGI_AUTHORIZER = 2;

    /**
     * A Filter FastCGI application receives all the information associated with an HTTP request, plus an extra stream of data from a file stored
     * on the Web server, and generates a "filtered" version of the data stream as an HTTP response.
     *
     * @var int
     */
    const FCGI_FILTER = 3;

    /**
     * Normal end of request.
     *
     * @var int
     */
    const FCGI_REQUEST_COMPLETE = 0;

    /**
     * Rejecting a new request.
     *
     * This happens when a Web server sends concurrent requests over one connection to an application that is designed to process
     * one request at a time per connection.
     *
     * @var int
     */
    const FCGI_CANT_MPX_CONN = 1;

    /**
     * Rejecting a new request.
     *
     * This happens when the application runs out of some resource, e.g. database connections.
     *
     * @var int
     */
    const FCGI_OVERLOADED = 2;

    /**
     * Rejecting a new request.
     *
     * This happens when the Web server has specified a role that is unknown to the application.
     *
     * @var int
     */
    const FCGI_UNKNOWN_ROLE = 3;

    /**
     * The maximum number of concurrent transport connections this application will accept, e.g. "1" or "10".
     *
     * @var string
     */
    const FCGI_MAX_CONNS = 'FCGI_MAX_CONNS';

    /**
     * The maximum number of concurrent requests this application will accept, e.g. "1" or "50".
     *
     * @var string
     */
    const FCGI_MAX_REQS = 'FCGI_MAX_REQS';

    /**
     * "0" if this application does not multiplex connections (i.e. handle concurrent requests over each connection), "1" otherwise.
     *
     * @var string
     */
    const FCGI_MPXS_CONNS = 'FCGI_MPXS_CONNS';
    
    /**
     * Underlying socket resource.
     * 
     * @var SocketStream
     */
    protected $socket;
    
    /**
     * IP address of the remote peer that connected to the local socket server.
     * 
     * @var string
     */
    protected $remoteAddress;
    
    /**
     * HTTP driver context provided by the FCGI endpoint.
     * 
     * @var HttpDriverContext
     */
    protected $context;
    
    /**
     * Incoming record processor.
     * 
     * @var Coroutine
     */
    protected $processor;
    
    /**
     * Channel that provides incoming HTTP requests to the application.
     * 
     * @var Channel
     */
    protected $incoming;
    
    /**
     * Handlers that assemble inbound HTTP requests.
     * 
     * @var array
     */
    protected $handlers = [];

    /**
     * Create a new FCGI connection.
     * 
     * @param SocketStream $socket
     * @param HttpDriverContext $context
     */
    public function __construct(SocketStream $socket, HttpDriverContext $context)
    {
        $this->socket = $socket;
        $this->context = $context;
        
        $parts = \explode(':', $socket->getRemoteAddress());
        \array_pop($parts);
        
        $this->remoteAddress = \implode(':', $parts);
        
        if ($this->remoteAddress === '') {
            $this->remoteAddress = '127.0.0.1';
        }
        
        $this->incoming = new Channel();
        $this->processor = new Coroutine($this->handleIncomingRecords(), true);
        $this->logger = new LoggerProxy(static::class, Http::LOG_CHANNEL);
    }
    
    /**
     * Shutdown the inbound record handler of the connection.
     */
    public function shutdown()
    {
        if ($this->processor) {
            $this->processor->cancel('Connection closed');
        }
    }
    
    /**
     * Read the next inbound HTTP request.
     */
    public function nextRequest(): Awaitable
    {
        return $this->incoming->receive();
    }
    
    /**
     * Get the IP address of the connected client.
     */
    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }
    
    /**
     * Send an FCGI record to the connected client.
     * 
     * @param Record $record
     * @return int Number of bytes being written.
     */
    public function sendRecord(Record $record): Awaitable
    {
        return $this->socket->write(\pack('CCnnxx', $record->version, $record->type, $record->requestId, \strlen($record->data)) . $record->data);
    }
    
    /**
     * Close the given request handler.
     */
    public function closeHandler(int $id, int $reason = self::FCGI_REQUEST_COMPLETE): Awaitable
    {
        if (!isset($this->handlers[$id])) {
            return new Success(false);
        }
        
        try {
            return $this->handlers[$id]->close($reason);
        } finally {
            unset($this->handlers[$id]);
        }
    }

    /**
     * Coroutine that processes inbound FCGI records.
     */
    protected function handleIncomingRecords(): \Generator
    {
        static $header = 'Cversion/Ctype/nid/nlen/Cpad/x';
        
        try {
            $peer = $this->socket->getRemoteAddress();
            
            $this->logger->debug('Accepted new FCGI connection from {peer}', [
                'peer' => $peer
            ]);
            
            while (true) {
                list ($version, $type, $id, $len, $pad) = \array_values(\unpack($header, yield $this->socket->readBuffer(8, true)));
                
                $payload = ($len > 0) ? yield $this->socket->readBuffer($len, true) : '';
                
                if ($pad > 0) {
                    yield $this->socket->readBuffer($pad, true);
                }
                
                $record = new Record($version, $type, $id, $payload);
                
                switch ($record->type) {
                    case Record::FCGI_BEGIN_REQUEST:
                        list ($role, $flags) = \array_values(\unpack('nrole/Cflags/x5', $record->data));
                        
                        if ($role != self::FCGI_RESPONDER) {
                            throw new \RuntimeException('Unsupported FGCI role');
                        }
                        
                        $this->handlers[$id] = new Handler($id, $this, $this->context, ($flags & self::FCGI_KEEP_CONNECTION) ? true : false);
                        break;
                    case Record::FCGI_ABORT_REQUEST:
                        if (!yield $this->closeHandler($id)) {
                            return;
                        }
                        break;
                    case Record::FCGI_PARAMS:
                        $this->handlers[$id]->handleParams($record);
                        break;
                    case Record::FCGI_STDIN:
                        yield from $this->handlers[$id]->handleStdin($record, $this->incoming);
                        break;
                }
            }
        } finally {
            $this->socket->close();
            $this->processor = null;
            
            $this->logger->debug('Closed FCGi connection to {peer}', [
                'peer' => $peer
            ]);
        }
    }
}
