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
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\BufferedDuplexStream;
use KoolKode\Async\Stream\DuplexStreamInterface;
use KoolKode\Async\Stream\SocketStream;
use Psr\Log\LoggerInterface;

use function KoolKode\Async\readBuffer;
use function KoolKode\Async\tempStream;

/**
 * HTTP/1 client endpoint.
 * 
 * @author Martin Schröder
 */
class Http1Connector
{
    protected $logger;
    
    public function __construct(LoggerInterface $logger = NULL)
    {
        $this->logger = $logger;
    }
    
    /**
     * Coroutine that sends an HTTP/1 request and returns the received HTTP response.
     * 
     * @param HttpRequest $request
     * @return Generator
     */
    public function send(HttpRequest $request): \Generator
    {
        $uri = $request->getUri();
        $secure = $uri->getScheme() === 'https';
        
        $host = $uri->getHost();
        $port = $uri->getPort() ?? ($secure ? Http::PORT_SECURE : Http::PORT);
        
        $stream = yield from SocketStream::connect($host, $port);
        
        try {
            if ($secure) {
                yield from $stream->encrypt(STREAM_CRYPTO_METHOD_TLS_CLIENT);
            }
            
            $request = $this->prepareRequest($request);
            
            yield from $this->sendRequest($stream, $request);
            
            $stream->shutdownSender();
            
            return yield from $this->processResponse($stream, $request);
        } catch (\Throwable $e) {
            $stream->close();
            
            throw $e;
        }
    }
    
    protected function prepareRequest(HttpRequest $request): HttpRequest
    {
        $request = $request->withHeader('Date', gmdate('D, d M Y H:i:s \G\M\T', time()));
        $request = $request->withHeader('Connection', 'close');
        
        $remove = [
            'Accept-Encoding',
            'Content-Encoding',
            'Content-Length',
            'Keep-Alive',
            'TE',
            'Transfer-Encoding'
        ];
        
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
                $tmp = yield tempStream();
                $request = $request->withHeader('Content-Length', '0');
            } elseif ($request->getProtocolVersion() === '1.0') {
                $tmp = yield tempStream();
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
                foreach ($values as $value) {
                    $message .= sprintf("%s: %s\n", $name, $value);
                }
            }
            
            yield from $stream->write($message . "\r\n");
            
            if ($chunked) {
                yield from $stream->write(sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk));
                
                while (!$body->eof()) {
                    $chunk = yield from $body->read();
                    
                    if ($chunk !== '') {
                        yield from $stream->write(sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk));
                    }
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
        $stream = new BufferedDuplexStream($stream);
        
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
        
        $dechunk = false;
        $decompress = NULL;
        
        if (isset($headers['transfer-encoding'])) {
            if ('chunked' === strtolower(implode(', ', $headers['transfer-encoding']))) {
                $dechunk = true;
            }
        }
        
        if (isset($headers['content-encoding'])) {
            switch (implode(', ', $headers['content-encoding'])) {
                case 'gzip':
                    $decompress = InflateInputStream::GZIP;
                    break;
                case 'deflate':
                    $decompress = InflateInputStream::DEFLATE;
                    break;
            }
        }
        
        $remove = [
            'connection',
            'content-encoding',
            'content-length',
            'keep-alive',
            'trailer',
            'transfer-encoding'
        ];
        
        foreach ($remove as $name) {
            if (isset($headers[$name])) {
                unset($headers[$name]);
            }
        }
        
        if ($dechunk) {
            $stream = new ChunkDecodedInputStream($stream, yield readBuffer($stream, 8192));
        }
        
        if ($decompress) {
            $stream = new InflateInputStream($stream, $decompress); 
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
