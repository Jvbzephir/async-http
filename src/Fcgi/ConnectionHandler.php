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

use KoolKode\Async\ExecutorInterface;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Task;
use KoolKode\Async\TaskInterruptedException;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\awaitAll;
use function KoolKode\Async\awaitRead;
use function KoolKode\Async\captureError;
use function KoolKode\Async\currentTask;
use function KoolKode\Async\readBuffer;
use function KoolKode\Async\runTask;
use function KoolKode\Async\tempStream;

/**
 * Handles FCGI request multiplexing via socket connection.
 * 
 * @author Martin Schröder
 */
class ConnectionHandler
{
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
     * Transport stream being used to send and receive records.
     * 
     * @var DuplexStreamInterface
     */
    protected $stream;
    
    /**
     * Max number of HTTP requests to be processed before closing the connection.
     * 
     * @var int
     */
    protected $maxRequests = 0;
    
    /**
     * Number of HTTP requests that habe been processed.
     * 
     * @var int
     */
    protected $processed = 0;
    
    /**
     * PSR logger isntance or NULL.
     * 
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * FCGI requests being processed keyed to their IDs.
     * 
     * @var array
     */
    protected $requests = [];
    
    /**
     * The main loop.
     * 
     * @var Task
     */
    protected $task;
    
    /**
     * Worker tasks still processing requests.
     * 
     * @var array
     */
    protected $workers = [];
    
    /**
     * Create a new FastCGI connection handler using the given stream as transport.
     * 
     * @param DuplexStreamInterface $stream
     * @param LoggerInterface $logger
     */
    public function __construct(DuplexStreamInterface $stream, LoggerInterface $logger = NULL)
    {
        $this->stream = $stream;
        $this->logger = $logger;
    }
    
    /**
     * Set max number of HTTP requests to be processed by the handler.
     * 
     * A value of "0" will never close the handler.
     * 
     * @param int $maxRequests
     */
    public function setMaxRequests(int $maxRequests)
    {
        $this->maxRequests = max(0, $maxRequests);
    }
    
    /**
     * Coroutine being used to dispatch incoming requests.
     * 
     * @param callable $action
     * @return Generator
     */
    public function run(callable $action): \Generator
    {
        static $hf = 'Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/x';
        
        $this->task = yield currentTask();
        
        try {
            while (true) {
                list ($version, $type, $requestId, $len, $pad) = array_values(unpack($hf, yield readBuffer($this->stream, 8)));
                
                if ($len > 0) {
                    $data = yield readBuffer($this->stream, $len);
                } else {
                    $data = '';
                }
                
                if ($pad > 0) {
                    yield readBuffer($this->stream, $pad);
                }
                
                if (false === yield from $this->handleRecord(new Record($version, $type, $requestId, $data), $action)) {
                    break;
                }
            }
        } catch (TaskInterruptedException $e) {
            // Bail out due to max requests reached.
        } finally {
            if (!empty($this->workers)) {
                yield awaitAll($this->workers);
            }
            
            $this->stream->close();
        }
    }
    
    /**
     * Handle the given FCGI record.
     * 
     * @param Record $record
     * @param callable $action
     * 
     * @throws \RuntimeException
     */
    protected function handleRecord(Record $record, callable $action): \Generator
    {
        if ($record->type === Record::FCGI_BEGIN_REQUEST) {
            if ($this->processed > $this->maxRequests) {
                return yield from $this->endRequest($record->requestId);
            }
            
            return yield from $this->handleBeginRecord($record);
        }
     
        $requestId = $record->requestId;
        
        if ($requestId < 0) {
            throw new \RuntimeException('FCGI record is missing request ID');
        }
        
        switch ($record->type) {
            case Record::FCGI_PARAMS:
                $buffer = $record->data;
                
                while ($buffer !== '') {
                    $this->readNameValuePair($buffer, $this->requests[$requestId]['params']);
                }
                break;
            case Record::FCGI_STDIN:
                if ($record->data === '') {
                    $worker = yield runTask($this->dispatch($requestId, $action), 'FCGI Worker');
                    $this->workers[$worker->id] = $worker;
                    
                    $worker->onComplete(function (ExecutorInterface $executor, Task $worker) {
                        unset($this->workers[$worker->id]);
                    });
                    
                    $worker->onError(function (ExecutorInterface $executor, Task $worker, \Throwable $e) use($requestId) {
                        try {
                            yield captureError($e);
                            yield from $this->endRequest($requestId);
                        } finally {
                            unset($this->workers[$worker->id]);
                        }
                    });
                    
                    $worker->onCancel(function (ExecutorInterface $executor, Task $worker) use($requestId) {
                        try {
                            yield from $this->endRequest($requestId);
                        } finally {
                            unset($this->workers[$worker->id]);
                        }
                    });
                } else {
                    yield from $this->requests[$requestId]['stdin']->write($record->data);
                }
                break;
            case Record::FCGI_ABORT_REQUEST:
                yield from $this->endRequest($requestId);
            default:
                throw new \RuntimeException('Unexpected record received');
        }
    }
    
    /**
     * Start handling a new incoming FCGI request.
     * 
     * @param Record $record
     * 
     * @throws \RuntimeException
     */
    protected function handleBeginRecord(Record $record): \Generator
    {
        if (isset($this->requests[$record->requestId])) {
            throw new \RuntimeException(sprintf('Unexpected FCGI_BEGIN_REQUEST record'));
        }
        
        $content = unpack('nrole/Cflags/x5', $record->data);
        
        $this->requests[$record->requestId] = [
            'started' => microtime(true),
            'keep-alive' => ($content['flags'] & self::FCGI_KEEP_CONNECTION) ? true : false,
            'params' => [],
            'stdin' => yield tempStream()
        ];
        
        if ($content['role'] != self::FCGI_RESPONDER) {
            throw new \RuntimeException('Unexpected FCGI role');
        }
    }
    
    /**
     * Coroutine that dispatches the given FCGI request.
     * 
     * @param int $requestId
     * @param callable $action
     */
    protected function dispatch(int $requestId, callable $action): \Generator
    {
        yield;
        
        if ($this->maxRequests > 0){
            $this->processed++;
        }
        
        $request = $this->createHttpRequest($requestId);
        $started = $this->requests[$requestId]['started'] ?? microtime(true);
        
        if ($this->logger) {
            $this->logger->debug('>> {method} {target} HTTP/{version}', [
                'method' => $request->getMethod(),
                'target' => $request->getRequestTarget(),
                'version' => $request->getProtocolVersion()
            ]);
        }
        
        $response = new HttpResponse(Http::CODE_OK, yield tempStream());
        $response = $response->withProtocolVersion($request->getProtocolVersion());
        
        $response = $action($request, $response);
        
        if ($response instanceof \Generator) {
            $response = yield from $response;
        }
        
        if (!$response instanceof HttpResponse) {
            throw new \RuntimeException(sprintf('Action must return an HTTP response, actual value is %s', is_object($response) ? get_class($response) : gettype($response)));
        }
        
        $response = $response->withProtocolVersion($request->getProtocolVersion());
        $response = yield from $this->writeResponse($requestId, $request, $response);
        
        yield from $this->endRequest($requestId);
        
        if ($this->logger) {
            $this->logger->debug('<< HTTP/{version} {status} {reason} << {duration} ms', [
                'version' => $response->getProtocolVersion(),
                'status' => $response->getStatusCode(),
                'reason' => $response->getReasonPhrase(),
                'duration' => round((microtime(true) - $started) * 1000)
            ]);
        }
    }
    
    /**
     * Creates an HTTP request object from a received FCGI request.
     * 
     * @param int $requestId
     */
    protected function createHttpRequest(int $requestId): HttpRequest
    {
        $params = $this->requests[$requestId]['params'];
        
        $uri = strtolower($params['REQUEST_SCHEME'] ?? 'http') . '://';
        $uri .= ($params['HTTP_HOST'] ?? gethostbyname(gethostname()));
        
        if (!empty($params['SERVER_PORT'])) {
            $uri .= ':' . (int) $params['SERVER_PORT'];
        }
        
        $uri = Uri::parse($uri . '/' . ltrim($params['REQUEST_URI'] ?? '', '/'));
        
        $request = new HttpRequest($uri, $this->requests[$requestId]['stdin']->rewind(), $params['REQUEST_METHOD'] ?? 'GET');
        $m = NULL;
        
        if (preg_match("'^HTTP/([1-9]\\.[0-9])$'i", $params['SERVER_PROTOCOL'] ?? '', $m)) {
            $request = $request->withProtocolVersion($m[1]);
        }
        
        foreach ($params as $k => $v) {
            if ('HTTP_' === substr($k, 0, 5)) {
                switch ($k) {
                    case 'HTTP_TRANSFER_ENCODING':
                    case 'HTTP_CONTENT_ENCODING':
                    case 'HTTP_KEEP_ALIVE':
                        // Skip these headers.
                        break;
                    default:
                        $request = $request->withAddedHeader(Http::normalizeHeaderName(str_replace('_', '-', substr($k, 5))), (string) $v);
                }
            }
        }
        
        if (isset($params['CONTENT_TYPE'])) {
            $request = $request->withHeader('Content-Type', $params['CONTENT_TYPE']);
        }
        
        if (isset($params['CONTENT_LENGTH'])) {
            $request = $request->withHeader('Content-Length', (string) $params['CONTENT_LENGTH']);
        }
        
        if (isset($params['CONTENT_MD5'])) {
            $request = $request->withHeader('Content-MD5', $params['CONTENT_MD5']);
        }
        
        return $request;
    }
    
    /**
     * Transmits HTTP response data to the FCGI server.
     * 
     * @param int $requestId
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @return HttpResponse
     */
    protected function writeResponse(int $requestId, HttpRequest $request, HttpResponse $response): \Generator
    {
        $remove = [
            'Connection',
            'Content-Encoding',
            'Content-Length',
            'Keep-Alive',
            'Trailer',
            'Transfer-Encoding',
            'Upgrade'
        ];
        
        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }
        
        $response = $response->withHeader('Date', gmdate('D, d M Y H:i:s \G\M\T', time()));
        
        if ('' === trim($response->getReasonPhrase())) {
            $response = $response->withStatus($response->getStatusCode(), Http::getReason($response->getStatusCode()));
        }
        
        $message = rtrim(sprintf("HTTP/%s %03u %s\r\n", $response->getProtocolVersion(), $response->getStatusCode(), $response->getReasonPhrase()));
        
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $message .= sprintf("%s: %s\r\n", $name, $value);
            }
        }
        
        $message .= "\r\n";
        
        yield from $this->writeRecord(new Record(Record::FCGI_VERSION_1, Record::FCGI_STDOUT, $requestId, $message));
        
        $body = $response->getBody();
        
        try {
            while (!$body->eof()) {
                yield from $this->writeRecord(new Record(Record::FCGI_VERSION_1, Record::FCGI_STDOUT, $requestId, yield from $body->read()));
            }
        } finally {
            $body->close();
        }
        
        return $response;
    }
    
    /**
     * Read an FCGI name value pair.
     * 
     * @param string $buffer Buffered input, parsed bytes will be removed.
     * @param array $pairs Parsed pairs will be set in the given array.
     */
    protected function readNameValuePair(string & $buffer, array & $pairs)
    {
        $len1 = $this->readFieldLength($buffer);
        $len2 = $this->readFieldLength($buffer);
        
        $pair = unpack("a{$len1}name/a{$len2}value", $buffer);
        $buffer = substr($buffer, $len1 + $len2);
        
        $pairs[$pair['name']] = $pair['value'];
    }

    /**
     * Read field length of a name or value transmitted within a pair.
     * 
     * @param string $buffer Buffered input, parsed bytes will be removed.
     */
    protected function readFieldLength(string & $buffer): int
    {
        $block = unpack('C', $buffer);
        $length = $block[1];
        
        if ($length & 0x80) {
            $block = unpack('N', $buffer);
            $length = $block[1] & 0x7FFFFFFF;
            $skip = 4;
        } else {
            $skip = 1;
        }
        
        $buffer = substr($buffer, $skip);
        
        return $length;
    }
    
    /**
     * Terminate the given FCGI request.
     * 
     * @param int $requestId
     * @param int $appStatus
     * @param int $protocolStatus
     */
    protected function endRequest(int $requestId, int $appStatus = 0, int $protocolStatus = self::FCGI_REQUEST_COMPLETE): \Generator
    {
        $content = pack('NCx3', $appStatus, $protocolStatus);
        
        yield from $this->writeRecord(new Record(Record::FCGI_VERSION_1, Record::FCGI_END_REQUEST, $requestId, $content));
        
        if (isset($this->requests[$requestId])) {
            $keep = $this->requests[$requestId]['keep-alive'];
            
            $this->requests[$requestId]['stdin']->close();
            
            unset($this->requests[$requestId]);
            
            if (!$keep || ($this->maxRequests > 0 && $this->processed >= $this->maxRequests)) {
                $this->processed = PHP_INT_MAX;
                
                if ($this->task !== NULL) {
                    $task = $this->task;
                    $this->task = NULL;
                    
                    unset($this->workers[(yield currentTask())->id]);
                    
                    $task->notify();
                }
            }
        }
    }

    /**
     * Transmit an FCGI record to the server.
     * 
     * @param Record $record
     */
    protected function writeRecord(Record $record): \Generator
    {
        $len = strlen($record->data);
        
        if ($len === 0) {
            return;
        }
        
        $header = pack('CCnnxx', $record->version, $record->type, $record->requestId, $len);
        
        yield from $this->stream->write($header);
        
        if ($len > 0) {
            yield from $this->stream->write($record->data);
        }
    }
}
