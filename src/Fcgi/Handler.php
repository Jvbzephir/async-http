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
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Logger;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Http\Body\DeferredBody;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Stream\ReadableChannelStream;
use KoolKode\Async\Stream\StreamClosedException;
use KoolKode\Async\Util\Channel;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * FastCGI-based HTTP request handler.
 * 
 * @author Martin Schröder
 */
class Handler implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    /**
     * Numeric ID of the HTTP request.
     * 
     * @var int
     */
    protected $id;
    
    /**
     * Keep connection alive after request has been processed?
     * 
     * @var bool
     */
    protected $keepAlive;
    
    /**
     * Have all HTTP headers been received?
     * 
     * @var bool
     */
    protected $received = false;
    
    /**
     * FCGI connection that handles all records.
     * 
     * @var Connection
     */
    protected $conn;
    
    /**
     * HTTP driver context.
     * 
     * @var HttpDriverContext
     */
    protected $context;
    
    /**
     * Decoded FCGI params for the current HTTP request.
     * 
     * @var array
     */
    protected $params = [];
    
    /**
     * Channel that buffers incoming HTTP body data.
     * 
     * @var Channel
     */
    protected $body;
    
    protected $pending;
    
    /**
     * Create a new FCGI request handler.
     * 
     * @param int $id
     * @param Connection $conn
     * @param HttpDriverContext $context
     * @param bool $keepAlive
     */
    public function __construct(int $id, Connection $conn, HttpDriverContext $context, bool $keepAlive = true)
    {
        $this->id = $id;
        $this->conn = $conn;
        $this->context = $context;
        $this->keepAlive = $keepAlive;
        
        $this->body = new Channel(4);
        $this->pending = new \SplObjectStorage();
        $this->logger = new Logger(static::class);
    }

    public function close(int $reason): Awaitable
    {
        return new Coroutine(function () use ($reason) {
            try {
                foreach ($this->pending as $task) {
                    $task->cancel('FCGI request closed');
                }
            } finally {
                $this->pending = new \SplObjectStorage();
            }
            
            $end = \pack('Ncx3', ($reason === Connection::FCGI_REQUEST_COMPLETE) ? 0 : 1, $reason);
            
            yield $this->conn->sendRecord(new Record(Record::FCGI_VERSION_1, Record::FCGI_END_REQUEST, $this->id, $end));
            
            return $this->keepAlive;
        });
    }

    public function isKeepAlive(): bool
    {
        return $this->keepAlive;
    }
    
    /**
     * Handle an FCGI params record.
     * 
     * @param Record $record
     */
    public function handleParams(Record $record)
    {
        $buffer = $record->data;
        
        while ($buffer !== '') {
            $this->readNameValuePair($buffer);
        }
    }

    /**
     * Handle an FCGI STDIN record that transfers request body data.
     * 
     * @param Record $record
     * @param Channel $incoming
     */
    public function handleStdin(Record $record, Channel $incoming): \Generator
    {
        if (!$this->received) {
            $this->received = true;
            
            $request = $this->buildRequest();
            
            $incoming->send([
                $this,
                $request
            ]);
        }
        
        if ($record->data === '') {
            return $this->body->close();
        }
        
        return yield $this->body->send($record->data);
    }
    
    /**
     * Send the given HTTP response using FCGI records.
     * 
     * @param HttpRequest $request
     * @param HttpResponse $response
     */
    public function sendResponse(HttpRequest $request, HttpResponse $response): \Generator
    {
        $response = $this->normalizeResponse($request, $response);
        
        $body = $response->getBody();
        $size = yield $body->getSize();
        $head = ($request->getMethod() === Http::HEAD);
        $nobody = $head || Http::isResponseWithoutBody($response->getStatusCode());
        
        if ($body instanceof DeferredBody) {
            $response = $response->withHeader('X-Accel-Buffering', 'no');
        }
        
        $buffer = $this->serializeHeaders($response, $nobody ? null : $size);
        
        yield $this->conn->sendRecord(new Record(Record::FCGI_VERSION_1, Record::FCGI_STDOUT, $this->id, $buffer . "\r\n"));
        
        if ($body instanceof DeferredBody) {
            $this->logger->info('{ip} "{method} {target} HTTP/{protocol}" {status} {size}', [
                'ip' => $request->getClientAddress(),
                'method' => $request->getMethod(),
                'target' => $request->getRequestTarget(),
                'protocol' => $request->getProtocolVersion(),
                'status' => $response->getStatusCode(),
                'size' => '-'
            ]);
            
            if ($nobody) {
                $body->close(false);
            } else {
                $task = new Coroutine($this->sendDeferredResponse($request, $body), true);
                
                $this->pending->attach($task);
                
                $task->when(function () use ($task) {
                    if ($this->pending->contains($task)) {
                        $this->pending->detach($task);
                    }
                });
                
                yield $task;
            }
        } else {
            $sent = 0;
            
            if (!$nobody) {
                $channel = Channel::fromStream(yield $body->getReadableStream(), true, 4096, $size);
                
                try {
                    while (null !== ($chunk = yield $channel->receive())) {
                        $sent += yield $this->conn->sendRecord(new Record(Record::FCGI_VERSION_1, Record::FCGI_STDOUT, $this->id, $chunk));
                    }
                } finally {
                    $channel->close();
                }
            }
            
            $this->logger->info('{ip} "{method} {target} HTTP/{protocol}" {status} {size}', [
                'ip' => $request->getClientAddress(),
                'method' => $request->getMethod(),
                'target' => $request->getRequestTarget(),
                'protocol' => $request->getProtocolVersion(),
                'status' => $response->getStatusCode(),
                'size' => $sent ?: '-'
            ]);
        }
        
        yield $this->conn->sendRecord(new Record(Record::FCGI_VERSION_1, Record::FCGI_STDOUT, $this->id, ''));
        yield $this->conn->sendRecord(new Record(Record::FCGI_VERSION_1, Record::FCGI_STDERR, $this->id, ''));
        
        $this->conn->closeHandler($this->id);
    }
    
    protected function sendDeferredResponse(HttpRequest $request, DeferredBody $body): \Generator
    {
        $body->start($request);
        
        $stream = yield $body->getReadableStream();
        $e = null;
        
        try {
            while (null !== ($chunk = yield $stream->read())) {
                yield $this->conn->sendRecord(new Record(Record::FCGI_VERSION_1, Record::FCGI_STDOUT, $this->id, $chunk));
            }
        } catch (StreamClosedException $e) {
            // Client disconnected from server.
        } finally {
            try {
                $stream->close();
            } finally {
                $body->close($e ? true : false);
            }
        }
    }

    /**
     * Normalize the given HTTP response to be sent using FCGI records.
     * 
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @return HttpResponse
     */
    protected function normalizeResponse(HttpRequest $request, HttpResponse $response): HttpResponse
    {
        static $remove = [
            'Connection',
            'Content-Length',
            'Keep-Alive',
            'Status',
            'Trailer',
            'Transfer-Encoding',
            'Upgrade'
        ];
        
        $response = $response->withProtocolVersion($request->getProtocolVersion());
        
        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }
        
        return $response->withHeader('Date', \gmdate(Http::DATE_RFC1123));
    }

    /**
     * Serialize HTTP headers to be sent in an FCGI record.
     * 
     * @param HttpResponse $response
     * @param int $size
     * @return string
     */
    protected function serializeHeaders(HttpResponse $response, int $size = null): string
    {
        $reason = \trim($response->getReasonPhrase());
        
        if ('' === $reason) {
            $reason = Http::getReason($response->getStatusCode());
        }
        
        $buffer = \sprintf("Status: %03u%s\r\n", $response->getStatusCode(), \rtrim(' ' . $reason));
        
        if ($size !== null) {
            $buffer .= "Content-Length: $size\r\n";
        }
        
        foreach ($response->getHeaders() as $name => $header) {
            $name = Http::normalizeHeaderName($name);
            
            foreach ($header as $value) {
                $buffer .= $name . ': ' . $value . "\r\n";
            }
        }
        
        return $buffer;
    }

    /**
     * Read an FCGI name value pair.
     *
     * @param string $buffer Buffered input, parsed bytes will be removed.
     */
    protected function readNameValuePair(string & $buffer)
    {
        $len1 = $this->readFieldLength($buffer);
        $len2 = $this->readFieldLength($buffer);
        
        $pair = \unpack("a{$len1}name/a{$len2}value", $buffer);
        $buffer = \substr($buffer, $len1 + $len2);
        
        $this->params[$pair['name']] = $pair['value'];
    }

    /**
     * Read field length of a name or value transmitted within a pair.
     *
     * @param string $buffer Buffered input, parsed bytes will be removed.
     */
    protected function readFieldLength(string & $buffer): int
    {
        $block = \unpack('C', $buffer);
        $len = $block[1];
        
        if ($len & 0x80) {
            $block = \unpack('N', $buffer);
            $len = $block[1] & 0x7FFFFFFF;
            $skip = 4;
        } else {
            $skip = 1;
        }
        
        $buffer = \substr($buffer, $skip);
        
        return $len;
    }

    /**
     * Assemble an HTTP request from received FCGI params.
     */
    protected function buildRequest(): HttpRequest
    {
        static $extra = [
            'CONTENT_TYPE' => 'Content-Type',
            'CONTENT_LENGTH' => 'Content-Length',
            'CONTENT_MD5' => 'Content-MD5'
        ];
        
        $uri = \strtolower($this->params['REQUEST_SCHEME'] ?? 'http') . '://';
        $uri .= ($this->params['HTTP_HOST'] ?? $this->context->getPeerName());
        
        if (!empty($this->params['SERVER_PORT'])) {
            $uri .= ':' . (int) $this->params['SERVER_PORT'];
        }
        
        $uri = Uri::parse($uri . '/' . \ltrim($this->params['REQUEST_URI'] ?? '', '/'));
        
        $request = new HttpRequest($uri, $this->params['REQUEST_METHOD'] ?? Http::GET, [], null, '1.1');
        
        foreach ($this->params as $k => $v) {
            if ('HTTP_' === \substr($k, 0, 5)) {
                switch ($k) {
                    case 'HTTP_TRANSFER_ENCODING':
                    case 'HTTP_CONTENT_ENCODING':
                    case 'HTTP_KEEP_ALIVE':
                        // Skip these headers...
                        break;
                    default:
                        $request = $request->withAddedHeader(\str_replace('_', '-', \substr($k, 5)), (string) $v);
                }
            }
        }
        
        foreach ($extra as $k => $v) {
            if (isset($this->params[$k])) {
                $request = $request->withHeader($v, $this->params[$k]);
            }
        }
        
        $addresses = [
            $this->conn->getRemoteAddress()
        ];
        
        if (isset($this->params['REMOTE_ADDR'])) {
            $addresses = \array_merge([
                $this->params['REMOTE_ADDR']
            ], $addresses);
        }
        
        $request = $request->withAddress(...$addresses);
        $request = $request->withAttribute(HttpDriverContext::class, $this->context);
        
        return $request->withBody(new StreamBody(new ReadableChannelStream($this->body)));
    }
}
