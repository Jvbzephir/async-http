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
use KoolKode\Async\Http\HttpConnectorContext;
use KoolKode\Async\Http\HttpConnectorInterface;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\BufferedDuplexStream;
use KoolKode\Async\Stream\BufferedInputStreamInterface;
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\SocketStream;
use KoolKode\Async\Stream\Stream;
use KoolKode\Async\Stream\StringInputStream;
use Psr\Log\LoggerInterface;

/**
 * HTTP/1 client endpoint.
 * 
 * @author Martin Schröder
 */
class Http1Connector implements HttpConnectorInterface
{
    protected $logger;
    
    protected $chunkedRequests = true;
    
    public function __construct(LoggerInterface $logger = NULL)
    {
        $this->logger = $logger;
    }
    
    /**
     * {@inheritdoc}
     */
    public function shutdown()
    {
        // TODO: Implement HTTP/1 keep-alive and kill pending connections here...
    }
    
    /**
     * {@inheritdoc}
     */
    public function getProtocols(): array
    {
        return [];
    }
    
    /**
     * {@inheritdoc}
     */
    public function getConnectorContext(HttpRequest $request)
    {
        // TODO: Context can be populated when keep-alive is implemented.
    }
    
    /**
     * Enable / disable sending non-empty request bodies using HTTP chunk encoding.
     * 
     * This is enabled by default, but can be disabled in case remote peers do not support it.
     * 
     * @param bool $chunked
     */
    public function setChunkedRequests(bool $chunked)
    {
        $this->chunkedRequests = $chunked;
    }
    
    /**
     * {@inheritdoc}
     */
    public function send(HttpRequest $request, HttpConnectorContext $context = NULL): \Generator
    {
        if ($context  !== NULL && $context->socket instanceof SocketStream) {
            $stream = $context->socket;
        } else {
            $uri = $request->getUri();
            $secure = $uri->getScheme() === 'https';
            
            $host = $uri->getHost();
            $port = $uri->getPort() ?? ($secure ? Http::PORT_SECURE : Http::PORT);
            
            $stream = yield from SocketStream::connect($host, $port, 'tcp', 5, $context->options ?? []);
            
            try {
                if ($secure) {
                    yield from $stream->encrypt();
                }
            } catch (\Throwable $e) {
                $stream->close();
                
                throw $e;
            }
        }
        
        try {
            $request = $this->prepareRequest($request);
            
            yield from $this->sendRequest($stream, $request);
            
            return yield from $this->processResponse($stream, $request);
        } catch (\Throwable $e) {
            $stream->close();
            
            throw $e;
        }
    }
    
    protected function prepareRequest(HttpRequest $request): HttpRequest
    {
        static $remove = [
            'Accept-Encoding',
            'Content-Encoding',
            'Content-Length',
            'Keep-Alive',
            'TE',
            'Transfer-Encoding'
        ];
        
        $request = $request->withHeader('Date', gmdate('D, d M Y H:i:s \G\M\T', time()));
        $request = $request->withHeader('Connection', 'close');
        
        foreach ($remove as $header) {
            $request = $request->withoutHeader($header);
        }
        
        if (function_exists('inflate_init')) {
            $request = $request->withHeader('Accept-Encoding', 'gzip, deflate');
        }
        
        return $request;
    }
    
    /**
     * Assemble and stream request data to the remote endpoint.
     * 
     * @param SocketStream $stream
     * @param HttpRequest $request
     * 
     * @throws \RuntimeException
     */
    protected function sendRequest(SocketStream $stream, HttpRequest $request): \Generator
    {
        $body = $request->getBody();
        
        try {
            $chunk = $body->eof() ? '' : yield from $body->read();
            $chunked = false;
            
            if ($chunk === '') {
                $tmp = new StringInputStream();
                $request = $request->withHeader('Content-Length', '0');
            } elseif (!$this->chunkedRequests || $request->getProtocolVersion() === '1.0') {
                $tmp = yield from Stream::temp();
                $size = yield from $tmp->write($chunk);
                
                while (!$body->eof()) {
                    $size += yield from $tmp->write(yield from $body->read());
                }
                
                $tmp->rewind();
                $request = $request->withHeader('Content-Length', (string) $size);
            } else {
                $request = $request->withHeader('Transfer-Encoding', 'chunked');
                $chunked = true;
            }
            
            $message = sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());
            
            foreach ($request->getHeaders() as $name => $values) {
                $name = Http::normalizeHeaderName($name);
                
                foreach ($values as $value) {
                    $message .= sprintf("%s: %s\n", $name, $value);
                }
            }
            
            yield from $stream->write($message . "\r\n");
            
            if ($chunked) {
                yield from $stream->write(sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk));
                
                while (!$body->eof()) {
                    $chunk = yield from $body->read();
                    
                    yield from $stream->write(sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk));
                }
                
                yield from $stream->write("0\r\n\r\n");
            } else {
                try {
                    while (!$tmp->eof()) {
                        yield from $stream->write(yield from $tmp->read());
                    }
                } finally {
                    $tmp->close();
                }
            }
            
            if ($this->logger) {
                $this->logger->debug('<< {method} {target} HTTP/{version}', [
                    'method' => $request->getMethod(),
                    'target' => $request->getRequestTarget(),
                    'version' => $request->getProtocolVersion()
                ]);
            }
        } finally {
            $body->close();
        }
    }
    
    /**
     * Read and parse raw HTTP response.
     * 
     * @param DuplexStreamInterface $stream
     * @param HttpRequest $request
     * 
     * @throws \RuntimeException
     */
    protected function processResponse(DuplexStreamInterface $stream, HttpRequest $request): \Generator
    {
        if (!$stream instanceof BufferedInputStreamInterface) {
            $stream = new BufferedDuplexStream($stream);
        }
        
        $line = yield from $stream->readLine();
        $m = NULL;
        
        if (!preg_match("'^HTTP/(1\\.[0-1])\s+([0-9]{3})\s*(.*)$'i", $line, $m)) {
            throw new \RuntimeException('Response did not contain a valid HTTP status line');
        }
        
        $headers = [];
        
        while (!$stream->eof()) {
            $line = yield from $stream->readLine();
            
            if ($line === '') {
                break;
            }
            
            $header = array_map('trim', explode(':', $line, 2));
            $headers[strtolower($header[0])][] = $header[1];
        }
        
        if ($request->getMethod() == 'HEAD') {
            $stream = new StringInputStream();
        } else {
            if (isset($headers['transfer-encoding'])) {
                $encodings = strtolower(implode(',', $headers['transfer-encoding']));
                $encodings = array_map('trim', explode(',', $encodings));
                
                if (in_array('chunked', $encodings)) {
                    $stream = yield from ChunkDecodedInputStream::open($stream);
                } else {
                    throw new \RuntimeException(sprintf('Unsupported transfer encoding: "%s"', implode(', ', $headers['transfer-encoding'])));
                }
            } elseif (isset($headers['content-length'])) {
                $len = Http::parseContentLength(implode(', ', $headers['content-length']));
                
                $stream = ($len === 0) ? new StringInputStream() : new LimitInputStream($stream, $len);
            } else {
                throw new \RuntimeException('Neighter transfer encoding nor content length specified in HTTP response');
            }
            
            if (isset($headers['content-encoding'])) {
                switch (implode(', ', $headers['content-encoding'])) {
                    case 'gzip':
                        $stream = yield from InflateInputStream::open($stream, InflateInputStream::GZIP);
                        break;
                    case 'deflate':
                        $stream = yield from InflateInputStream::open($stream, InflateInputStream::DEFLATE);
                        break;
                    default:
                        throw new \RuntimeException(sprintf('Unsupported content-encoding: "%s"', implode(', ', $headers['content-encoding'])));
                }
            }
        }
        
        static $remove = [
            'connection',
            'content-encoding',
            'keep-alive',
            'trailer',
            'transfer-encoding'
        ];
        
        foreach ($remove as $name) {
            if (isset($headers[$name])) {
                unset($headers[$name]);
            }
        }
        
        $response = new HttpResponse((int) $m[2], $stream, $headers);
        $response = $response->withProtocolVersion($m[1]);
        $response = $response->withStatus((int) $m[2], trim($m[3]));
        
        if ($this->logger) {
            $this->logger->debug('>> HTTP/{version} {status} {reason}', [
                'version' => $response->getProtocolVersion(),
                'status' => $response->getStatusCode(),
                'reason' => $response->getReasonPhrase()
            ]);
        }
        
        return $response;
    }
}
