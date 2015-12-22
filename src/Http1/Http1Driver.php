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
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Stream\BufferedDuplexStream;
use KoolKode\Async\Stream\DuplexStreamInterface;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\is_runnable;

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
        $cached = true;
        $socket = new DirectUpgradeDuplexStream($socket, $cached);
        
        // Attempt to upgrade HTTP connection based on data sent by client
        if (NULL !== ($handler = yield from $endpoint->findDirectUpgradeHandler($socket))) {
            $cached = false;
            $response = yield from $handler->upgradeDirectConnection($endpoint, $socket, $action);
            
            if ($response instanceof HttpResponse) {
                try {
                    return yield from $this->sendResponse($socket, $response);
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
                return;
            }
            
            $m = NULL;
            
            if (!preg_match("'^(\S+)\s+(.+)\s+HTTP/(1\\.[01])$'i", $line, $m)) {
                throw new \RuntimeException('Bad Request');
            }
            
            $headers = [];
            
            while (!$reader->eof()) {
                $line = yield from $reader->readLine();
                
                if ($line === '') {
                    break;
                }
                
                $header = array_map('trim', explode(':', $line, 2));
                $headers[strtolower($header[0])][] = $header;
            }
            
            $uri = $endpoint->isEncrypted() ? 'https://' : 'http://';
            $uri .= $endpoint->getPeerName() . '/' . ltrim($m[2], '/');
            
            $uri = Uri::parse($uri);
            $uri = $uri->withPort($endpoint->getPort());
            
//             $server = [
//                 'SERVER_PROTOCOL' => 'HTTP/' . $m[3],
//                 'REQUEST_METHOD' => $m[1],
//                 'REQUEST_URI' => '/' . ltrim($m[2], '/'),
//                 'SCRIPT_NAME' => '',
//                 'SERVER_NAME' => $endpoint->getPeerName(),
//                 'SERVER_PORT' => $endpoint->getPort(),
//                 'REQUEST_TIME' => time(),
//                 'REQUEST_TIME_FLOAT' => microtime(true),
//                 'HTTP_HOST' => empty($headers['host'][0][1]) ? $endpoint->getPeerName() : $headers['host'][0][1]
//             ];
            
//             $query = [];
//             parse_str($uri->getQuery(), $query);
            
            $request = new HttpRequest();
            $request = $request->withMethod($m[1]);
            $request = $request->withProtocolVersion($m[3]);
            $request = $request->withUri($uri);
            
            foreach ($headers as $header) {
                foreach ($header as $data) {
                    $request = $request->withHeader(Http::normalizeHeaderName($data[0]), $data[1]);
                }
            }
            
            if ($this->logger) {
                $this->logger->debug('>> {method} {target} HTTP/{version}', [
                    'method' => $request->getMethod(),
                    'target' => $request->getRequestTarget(),
                    'version' => $request->getProtocolVersion()
                ]);
            }
            
            $response = new HttpResponse();
            $response = $response->withProtocolVersion($request->getProtocolVersion());
        } catch (\Throwable $e) {
            $socket->close();
            
            throw $e;
        }
        
        // Attempt to upgrade HTTP connection if requested by the client:
        if (NULL !== ($handler = $this->findUpgradeHandler($request, $endpoint))) {
            $response = yield from $handler->upgradeConnection($request, $response, $endpoint, $reader, $action);
            
            if ($response instanceof HttpResponse) {
                return yield from $this->sendResponse($socket, $response);
            }
            
            return;
        }
        
        try {
            $result = $action($request, $response);
            
            if (is_runnable($result)) {
                $result = yield $result;
            }
            
            return yield from $this->sendResponse($socket, $result[0]);
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
    protected function sendResponse(DuplexStreamInterface $socket, HttpResponse $response): \Generator
    {
        $response = $response->withHeader('Date', gmdate('D, d M Y H:i:s \G\M\T', time()));
        $response = $response->withHeader('Connection', 'close');
        $response = $response->withHeader('Transfer-Encoding', 'chunked');
        
        $message = sprintf("HTTP/%s %03u %s\r\n", $response->getProtocolVersion(), $response->getStatusCode(), $response->getReasonPhrase());
        
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $message .= sprintf("%s: %s\r\n", $name, $value);
            }
        }
        
        yield from $socket->write($message . "\r\n");
        
        $in = $response->getBody();
        
        try {
            while (!$in->eof()) {
                $chunk = yield from $in->read(8184);
                
                if ($chunk !== '') {
                    yield from $socket->write(sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk));
                }
            }
        
            yield from $socket->write("0\r\n\r\n");
        } finally {
            $in->close();
        }
        
        if ($this->logger) {
            $this->logger->debug('<< HTTP/{version} {status} {reason}', [
                'version' => $response->getProtocolVersion(),
                'status' => $response->getStatusCode(),
                'reason' => $response->getReasonPhrase()
            ]);
        }
    }
}
