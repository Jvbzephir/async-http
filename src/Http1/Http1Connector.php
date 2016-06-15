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
use KoolKode\Async\Http\HttpContext;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Stream\BufferedDuplexStreamInterface;
use KoolKode\Async\Stream\Stream;
use KoolKode\Async\Stream\StringInputStream;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\fileOpenTemp;

/**
 * HTTP/1 client endpoint.
 * 
 * @author Martin Schröder
 */
class Http1Connector implements HttpConnectorInterface
{
    protected $context;
    
    protected $logger;
    
    protected $chunkedRequests = true;
    
    public function __construct(HttpContext $context = NULL, LoggerInterface $logger = NULL)
    {
        $this->context = $context ?? new HttpContext();
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
    public function getHttpContext(): HttpContext
    {
        return $this->context;
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
        if ($context  !== NULL && $context->socket instanceof BufferedDuplexStreamInterface) {
            $stream = $context->socket;
        } else {
            $uri = $request->getUri();
            $secure = $uri->getScheme() === 'https';
            
            $host = $uri->getHost();
            $port = $uri->getPort() ?? ($secure ? Http::PORT_SECURE : Http::PORT);
            
            $stream = yield from SocketStream::connect($host, $port, 'tcp', 5, array_replace_recursive($context->options ?? [], [
                'socket' => [
                    'tcp_nodelay' => true
                ]
            ]));
            
            try {
                if ($secure) {
                    yield from $stream->encryptClient();
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
        
        $encodings = Http1Body::getAvailableCompressionEncodings();
        
        if (!empty($encodings)) {
            $request = $request->withHeader('Accept-Encoding', implode(', ', $encodings));
        }
        
        return $request;
    }
    
    /**
     * Assemble and stream request data to the remote endpoint.
     * 
     * @param BufferedDuplexStreamInterface $stream
     * @param HttpRequest $request
     * 
     * @throws \RuntimeException
     */
    protected function sendRequest(BufferedDuplexStreamInterface $stream, HttpRequest $request): \Generator
    {
        $body = $request->getBody();
        $size = yield from $body->getSize();
        $in = yield from $body->getInputStream();
        
        try {
            $chunk = $in->eof() ? '' : yield from Stream::readBuffer($in, 4088);
            $chunked = false;
            
            if ($chunk === '') {
                $in->close();
                
                $in = new StringInputStream();
                $request = $request->withHeader('Content-Length', '0');
            } elseif ($size !== NULL) {
                $request = $request->withHeader('Content-Length', (string) $size);
            } elseif (!$this->chunkedRequests || $request->getProtocolVersion() === '1.0') {
                $tmp = yield fileOpenTemp();
                $size = yield from $tmp->write($chunk);
                $chunk = '';
                
                try {
                    $size += yield from Stream::copy($in, $tmp);
                } finally {
                    $in->close();
                }
                
                $in = $tmp->rewind();
                
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
            $stream->flush();
            
            if ($chunked) {
                yield from $stream->write(sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk));
                
                yield from Stream::copy($in, $stream, 4, 4088, function (string $chunk) {
                    return sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk);
                });
                
                yield from $stream->write("0\r\n\r\n");
            } else {
                if ($chunk !== '') {
                    if (!$in->eof()) {
                        // Ensure first chunk is 4K bytes.
                        $chunk .= yield from Stream::readBuffer($in, 8);
                    }
                    
                    yield from $stream->write($chunk);
                }
                
                yield from Stream::copy($in, $stream, 4, 4096);
            }
            
            $stream->flush();
            
            if ($this->logger) {
                $this->logger->debug('<< {method} {target} HTTP/{version}', [
                    'method' => $request->getMethod(),
                    'target' => $request->getRequestTarget(),
                    'version' => $request->getProtocolVersion()
                ]);
            }
        } finally {
            $in->close();
        }
    }
    
    /**
     * Read and parse raw HTTP response.
     * 
     * @param BufferedDuplexStreamInterface $stream
     * @param HttpRequest $request
     * 
     * @throws \RuntimeException
     */
    protected function processResponse(BufferedDuplexStreamInterface $stream, HttpRequest $request): \Generator
    {
        $line = yield from $stream->readLine();
        $headers = [];
        $m = NULL;
        
        if (!preg_match("'^HTTP/(1\\.[0-1])\s+([0-9]{3})\s*(.*)$'i", $line, $m)) {
            throw new \RuntimeException('Response did not contain a valid HTTP status line');
        }
        
        while (!$stream->eof()) {
            $line = yield from $stream->readLine();
            
            if ($line === '') {
                break;
            }
            
            $header = array_map('trim', explode(':', $line, 2));
            $headers[strtolower($header[0])][] = $header[1];
        }
        
        $response = new HttpResponse((int) $m[2], $headers, $m[1]);
        $response = $response->withStatus((int) $m[2], trim($m[3]));
        
        $body = Http1Body::fromHeaders($stream, $response);
        
        $response = $response->withBody($body);
        
        static $remove = [
            'Connection',
            'Content-Encoding',
            'Keep-Alive',
            'Trailer',
            'Transfer-Encoding'
        ];
        
        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }
        
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
