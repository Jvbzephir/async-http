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

use KoolKode\Async\Http\FileBody;
use KoolKode\Async\Http\Header\AcceptEncodingHeader;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpDriverInterface;
use KoolKode\Async\Http\HttpEndpoint;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\StatusException;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Stream\BufferedDuplexStream;
use KoolKode\Async\Stream\BufferedDuplexStreamInterface;
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\ResourceStreamInterface;
use KoolKode\Async\Stream\StreamException;
use KoolKode\Async\Stream\Stream;
use KoolKode\Async\Stream\StringInputStream;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\captureError;
use function KoolKode\Async\currentExecutor;
use function KoolKode\Async\fileOpenTemp;

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
        
        try {
            $socket = ($socket instanceof BufferedDuplexStreamInterface) ? $socket : new BufferedDuplexStream($socket);
            
            // Bail out when no HTTP request line is received.
            try {
                $line = yield from $socket->readLine();
            } catch (StreamException $e) {
                return;
            }
            
            if (empty($line)) {
                return;
            }
            
            $m = NULL;
            
            if (!preg_match("'^(\S+)\s+(.+)\s+HTTP/([1-9]+(?:\\.[0-9]+)?)$'i", $line, $m)) {
                throw new StatusException(Http::CODE_BAD_REQUEST);
            }
            
            $headers = [];
            
            while (!$socket->eof()) {
                $line = yield from $socket->readLine();
                
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
            $uri = Uri::parse($uri)->withPort($endpoint->getPort());
            
            $request = new HttpRequest($uri, $m[1], $headers, $m[3]);
            
            try {
                $body = Http1Body::fromHeaders($socket, $request);
                $body->setCascadeClose(false);
            } catch (\Exception $e) {
                throw new StatusException($e->getCode(), $e);
            }
            
            $request = $request->withBody($body);
            
            $response = new HttpResponse();
            $response = $response->withProtocolVersion($request->getProtocolVersion());
            
            // Attempt to upgrade HTTP connection before further processing.
            if (NULL !== ($handler = $this->findUpgradeHandler($request, $endpoint))) {
                $response = yield from $handler->upgradeConnection($socket, $request, $response, $endpoint, $action);
                
                if ($response instanceof HttpResponse) {
                    return yield from $this->sendResponse($endpoint, $socket, $request, $response, $request->getMethod() == 'HEAD', $started);
                }
                
                return;
            }
            
            if ($this->logger) {
                $this->logger->debug('>> {method} {target} HTTP/{version}', [
                    'method' => $request->getMethod(),
                    'target' => $request->getRequestTarget(),
                    'version' => $request->getProtocolVersion()
                ]);
            }
        } catch (StatusException $e) {
            yield captureError($e);
            
            $response = new HttpResponse($e->getCode());
            
            yield from $this->sendResponse($endpoint, $socket, $request, $response, isset($request) && $request->getMethod() == 'HEAD', $started);
            
            $socket->close();
            
            return;
        } catch (StreamException $e) {
            yield captureError($e);
            
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
        
        try {
            static $remove = [
                'Connection',
                'Transfer-Encoding',
                'Content-Encoding',
                'Keep-Alive'
            ];
            
            foreach ($remove as $name) {
                $request = $request->withoutHeader($name);
            }
            
            $response = $action($request, $response);
            
            if ($response instanceof \Generator) {
                $response = yield from $response;
            }
            
            if (!$response instanceof HttpResponse) {
                throw new \RuntimeException(sprintf('Action must return an HTTP response, actual value is %s', \is_object($response) ? get_class($response) : gettype($response)));
            }
            
            return yield from $this->sendResponse($endpoint, $socket, $request, $response, $request->getMethod() == 'HEAD', $started);
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
        $upgrade = [];
        
        if (in_array('upgrade', $this->splitHeaderValues($request->getHeaderLine('Connection')), true)) {
            $upgrade = $this->splitHeaderValues($request->getHeaderLine('Upgrade'));
        } else {
            $upgrade = [];
        }
        
        $upgrade[] = '';
        
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
     * @param HttpEndpoint $endpoint
     * @param DuplexStreamInterface $socket
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @param bool $head
     * @param float $started
     * @return Generator
     */
    protected function sendResponse(HttpEndpoint $endpoint, DuplexStreamInterface $socket, HttpRequest $request, HttpResponse $response, bool $head = false, float $started = NULL): \Generator
    {
        if ($started === NULL) {
            $started = microtime(true);
        }
        
        static $remove = [
            'Content-Encoding',
            'Content-Length',
            'Keep-Alive',
            'TE',
            'Transfer-Encoding'
        ];
        
        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }
        
        $body = $response->getBody();
        $size = yield from $body->getSize();
        $resource = ($socket instanceof ResourceStreamInterface) ? $socket->getResource() : NULL;
        
        $chunked = ($size === NULL && !$head && $response->getProtocolVersion() !== '1.0');
        
        $response = $response->getBody()->prepareMessage($response);
        $response = $response->withHeader('Date', gmdate(Http::DATE_FORMAT_RFC1123, time()));
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
        
        $compression = NULL;
        $compressionMethod = NULL;
        
        if (DeflateInputStream::isAvailable() && $endpoint->getHttpContext()->isCompressible($response)) {
            foreach (AcceptEncodingHeader::fromMessage($request)->getEncodings() as $encoding) {
                switch ($encoding->getName()) {
                    case 'gzip':
                        $compression = 'gzip';
                        $compressionMethod = DeflateInputStream::GZIP;
                        break 2;
                    case 'deflate':
                        $compression = 'deflate';
                        $compressionMethod = DeflateInputStream::DEFLATE;
                        break 2;
                }
            }
        }
        
        if ($chunked || $compression !== NULL) {
            $in = yield from $body->getInputStream();
            
            if ($compression !== NULL) {
                $in = yield from DeflateInputStream::open($in, $compressionMethod);
                $message .= sprintf("Content-Encoding: %s\r\n", $compression);
            }
            
            $chunk = $in->eof() ? '' : yield from Stream::readBuffer($in, 4088);
            
            if ($chunk === '') {
                $in->close();
                
                $in = new StringInputStream('');
                $message .= "Content-Length: 0\r\n";
            } else {
                $message .= "Transfer-Encoding: chunked\r\n";
            }
        } elseif ($size !== NULL) {
            if (!$body instanceof FileBody || $resource === NULL) {
                $body = yield from $body->getInputStream();
            }
            
            $message .= sprintf("Content-Length: %u\r\n", $size);
        } else {
            $in = yield from $body->getInputStream();
            $body = yield fileOpenTemp();
            $size = 0;
            
            try {
                $size += yield from Stream::copy($in, $body);
            } finally {
                $in->close();
            }
            
            $message .= sprintf("Content-Length: %u\r\n", $size);
            
            $body->rewind();
        }
        
        try {
            yield from $socket->write($message . "\r\n");
            $socket->flush();
            
            if ($chunked || $compression !== NULL) {
                try {
                    if ($chunk !== '') {
                        yield from $socket->write(sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk));
                        
                        yield from Stream::copy($in, $socket, 4, 4088, function (string $chunk) {
                            return sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk);
                        });
                        
                        yield from $socket->write("0\r\n\r\n");
                    }
                } finally {
                    $in->close();
                }
            } else {
                if ($body instanceof FileBody) {
                    if ($resource !== NULL && !$endpoint->isEncrypted()) {
                        yield from (yield currentExecutor())->getFilesystem()->sendfile($body->getFile(), $resource);
                    } else {
                        $body = yield from $body->getInputStream();
                    }
                } else {
                    try {
                        if (!$head) {
                            yield from Stream::copy($body, $socket, 4, 4096);
                        }
                    } finally  {
                        $body->close();
                    }
                }
            }
            
            $socket->flush();
            
            if ($this->logger) {
                $this->logger->debug('<< HTTP/{version} {status} {reason} << {duration} ms', [
                    'version' => $response->getProtocolVersion(),
                    'status' => $response->getStatusCode(),
                    'reason' => $response->getReasonPhrase(),
                    'duration' => round((microtime(true) - $started) * 1000)
                ]);
            }
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
