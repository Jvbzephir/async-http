<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpDriverInterface;
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Stream\BufferedDuplexStream;
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\SocketException;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\tempStream;

/**
 * HTTP/1 server endpoint.
 * 
 * @author Martin Schröder
 */
class Http1Driver implements HttpDriverInterface
{
    /**
     * Optional logger instance.
     * 
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(LoggerInterface $logger = NULL)
    {
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
            'http/1.1'
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getSslOptions(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function handleConnection(HttpEndpoint $endpoint, DuplexStreamInterface $socket, callable $action): \Generator
    {
        $started = microtime(true);
        $cached = true;
        $socket = new DirectUpgradeDuplexStream($socket, $cached);
        
        // Attempt to upgrade HTTP connection based on data sent by client
        if (NULL !== ($handler = yield from $endpoint->findDirectUpgradeHandler($socket))) {
            $cached = false;
            $response = yield from $handler->upgradeDirectConnection($endpoint, $socket, $action);
            
            if ($response instanceof HttpResponse) {
                try {
                    return yield from $this->sendResponse($socket, $response, $started);
                } finally {
                    $socket->close();
                }
            }
            
            return;
        }
        
        $cached = false;
        
        try {
            $reader = new BufferedDuplexStream($socket);
            
            $line = yield from $reader->readLine();
            
            if ($line === false) {
                throw new StatusException(Http::CODE_BAD_REQUEST);
            }
            
            $m = NULL;
            
            if (!preg_match("'^(\S+)\s+(.+)\s+HTTP/(1\\.[01])$'i", $line, $m)) {
                throw new StatusException(Http::CODE_BAD_REQUEST);
            }
            
            $headers = [];
            
            while (!$reader->eof()) {
                $line = yield from $reader->readLine();
                
                if ($line === '') {
                    break;
                }
                
                $header = array_map('trim', explode(':', $line, 2));
                $headers[strtolower($header[0])][] = $header[1];
            }
            
            $n = NULL;
            if (preg_match("'^(https?)://([^/]+)/(.*)$'i", $m[2], $n)) {
                $m[2] = $n[3];
            }
            
            $uri = $endpoint->isEncrypted() ? 'https://' : 'http://';
            $uri .= $endpoint->getPeerName() . '/' . ltrim($m[2], '/');
            
            $uri = Uri::parse($uri);
            $uri = $uri->withPort($endpoint->getPort());
            
            if (isset($headers['transfer-encoding'])) {
                $encodings = strtolower(implode(',', $headers['transfer-encoding']));
                $encodings = array_map('trim', explode(',', $encodings));
                
                if (in_array('chunked', $encodings)) {
                    $reader = new ChunkDecodedInputStream($reader, yield from $reader->read(), false);
                } else {
                    throw new StatusException(Http::CODE_NOT_IMPLEMENTED);
                }
            } elseif (isset($headers['content-length'])) {
                try {
                    $len = Http::parseContentLength(implode(', ', $headers['content-length']));
                } catch (\Throwable $e) {
                    throw new StatusException(Http::CODE_BAD_REQUEST, $e);
                }
                
                $reader = ($len === 0) ? yield tempStream() : new LimitInputStream($reader, $len, false);
            } else {
                // Dropping request body if neighter content-length nor chunked encoding are specified.
                $reader = yield tempStream();
            }
            
            $remove = [
                'connection',
                'transfer-encoding',
                'content-encoding',
                'keep-alive'
            ];
            
            foreach ($remove as $name) {
                unset($headers[$name]);
            }
            
            $request = new HttpRequest($uri, $reader, $m[1], $headers);
            $request = $request->withProtocolVersion($m[3]);
            
            if ($this->logger) {
                $this->logger->debug('>> {method} {target} HTTP/{version}', [
                    'method' => $request->getMethod(),
                    'target' => $request->getRequestTarget(),
                    'version' => $request->getProtocolVersion()
                ]);
            }
            
            $response = new HttpResponse(Http::CODE_OK, yield tempStream());
            $response = $response->withProtocolVersion($request->getProtocolVersion());
        } catch (StatusException $e) {
            yield from $this->sendResponse($socket, new HttpResponse($e->getCode(), yield tempStream()), $started);
            
            $socket->close();
            
            return;
        } catch (SocketException $e) {
            $socket->close();
            
            if ($this->logger) {
                $this->logger->debug('Dropped client due to socket error: {error} in {file} at line {line}', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
            
            return;
        } catch (\Throwable $e) {
            $socket->close();
            
            throw $e;
        }
        
        // Attempt to upgrade HTTP connection if requested by the client:
        if (NULL !== ($handler = $this->findUpgradeHandler($request, $endpoint))) {
            $response = yield from $handler->upgradeConnection($request, $response, $endpoint, $reader, $action);
            
            if ($response instanceof HttpResponse) {
                return yield from $this->sendResponse($socket, $response, $started);
            }
            
            return;
        }
        
        try {
            $response = $action($request, $response);
            
            if ($response instanceof \Generator) {
                $response = yield from $response;
            }
            
            if (!$response instanceof HttpResponse) {
                throw new \RuntimeException(sprintf('Action must return an HTTP response, actual value is %s', is_object($response) ? get_class($response) : gettype($response)));
            }
            
            return yield from $this->sendResponse($socket, $response, $started);
        } finally {
            $socket->close();
        }
    }
    
    /**
     * Search for an appropriate HTTP/1 upgrade handler.
     * 
     * @param HttpRequest $request
     * @param HttpEndpoint $endpoint
     * @return HttpUpgradeHandlerInterface or NULL when no such handler was found.
     */
    protected function findUpgradeHandler(HttpRequest $request, HttpEndpoint $endpoint)
    {
        if (!in_array('upgrade', $this->splitHeaderValues($request->getHeaderLine('Connection')), true)) {
            return;
        }
        
        $upgrade = $this->splitHeaderValues($request->getHeaderLine('Upgrade'));
        
        if (empty($upgrade)) {
            return;
        }
        
        foreach ($upgrade as $protocol) {
            if (NULL !== ($handler = $endpoint->findUpgradeHandler($protocol, $request))) {
                return $handler;
            }
        }
    }
    
    /**
     * Split concatenated header values into an array.
     * 
     * @param string $header
     * @param string $separator
     * @return array
     */
    protected function splitHeaderValues(string $header, string $separator = ','): array
    {
        return array_map(function ($val) {
            return strtolower(trim($val));
        }, explode($separator, $header));
    }
    
    /**
     * Serialize HTTP response and transmit data over the wire.
     * 
     * @param DuplexStreamInterface $socket
     * @param HttpResponse $response
     * @return Generator
     */
    protected function sendResponse(DuplexStreamInterface $socket, HttpResponse $response, float $started = NULL): \Generator
    {
        if ($started === NULL) {
            $started = microtime(true);
        }
        
        $remove = [
            'Transfer-Encoding',
            'Content-Encoding',
            'Keep-Alive'
        ];
        
        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }
        
        $chunked = ($response->getProtocolVersion() !== '1.0');
        
        $response = $response->withHeader('Date', gmdate('D, d M Y H:i:s \G\M\T', time()));
        $response = $response->withHeader('Connection', 'close');
        
        if ('' === trim($response->getReasonPhrase())) {
            $response = $response->withStatus($response->getStatusCode(), Http::getReason($response->getStatusCode(), ''));
        }
        
        $message = sprintf("HTTP/%s %03u %s\r\n", $response->getProtocolVersion(), $response->getStatusCode(), $response->getReasonPhrase());
        
        foreach ($response->getHeaders() as $name => $values) {
            $name = Http::normalizeHeaderName($name);
            
            foreach ($values as $value) {
                $message .= sprintf("%s: %s\r\n", $name, $value);
            }
        }
        
        if ($chunked) {
            $in = $response->getBody();
            $chunk = $in->eof() ? '' : yield from $in->read();
            
            if ($chunk === '') {
                $message .= "Content-Length: 0\r\n";
            } else {
                $message .= "Transfer-Encoding: chunked\r\n";
            }
        } else {
            $in = $response->getBody();
            $body = yield tempStream();
            $length = 0;
            
            try {
                while (!$in->eof()) {
                    $length += yield from $body->write(yield from $in->read());
                }
            } finally {
                $in->close();
            }
            
            $message .= sprintf("Content-Length: %u\r\n", $length);
            
            $body->rewind();
        }
        
        try {
            yield from $socket->write($message . "\r\n");
            
            if ($chunked) {
                try {
                    if ($chunk !== '') {
                        yield from $socket->write(sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk));
                        
                        while (!$in->eof()) {
                            $chunk = yield from $in->read(8184);
                            
                            yield from $socket->write(sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk));
                        }
                        
                        yield from $socket->write("0\r\n\r\n");
                    }
                } finally {
                    $in->close();
                }
            } else {
                try {
                    while (!$body->eof()) {
                        yield from $socket->write(yield from $body->read());
                    }
                } finally  {
                    $body->close();
                }
            }
            
            if ($this->logger) {
                $this->logger->debug('<< HTTP/{version} {status} {reason} << {duration} ms', [
                    'version' => $response->getProtocolVersion(),
                    'status' => $response->getStatusCode(),
                    'reason' => $response->getReasonPhrase(),
                    'duration' => round((microtime(true) - $started) * 1000)
                ]);
            }
        } catch (SocketException $e) {
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
