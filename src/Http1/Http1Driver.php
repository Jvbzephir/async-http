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

use KoolKode\Async\CopyBytes;
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Stream\DuplexStream;
use KoolKode\Async\Stream\WritableDeflateStream;

class Http1Driver
{
    protected $keepAliveSupported = false;
    
    public function getProtocols(): array
    {
        return [
            'http/1.1'
        ];
    }
    
    public function handleConnection(DuplexStream $stream): \Generator
    {
        try {
            do {
                $request = yield from $this->parseRequest($stream);
                
                $response = new HttpResponse();
                $response = $response->withHeader('Served-By', 'KoolKode HTTP');
                $response = $response->withBody(new \KoolKode\Async\Http\StringBody('Hello Test Client :)'));
                
                if (!$this->keepAliveSupported) {
                    $close = true;
                } elseif ($request->getProtocolVersion() == '1.0') {
                    // HTTP/1.0 does not support keep alive.
                    $close = true;
                } elseif ('close' === $request->getHeaderLine('Connection')) {
                    // Close connection if client does not want to use keep alive.
                    $close = true;
                } else {
                    $close = false;
                }
                
                yield from $this->sendResponse($stream, $request, $response, $close);
            } while (!$close);
        } catch (\Throwable $e) {
            fwrite(STDERR, "\n$e\n");
        } finally {
            $stream->close();
        }
    }
    
    protected function parseRequest(DuplexStream $stream): \Generator
    {
        $line = yield $stream->readLine();
        $parts = \preg_split("'\s+'", \trim((string) $line), 3);
        
        if (\count($parts) !== 3) {
            throw new \RuntimeException('Invalid HTTP request received');
        }
        
        if ($parts[2] !== 'HTTP/1.0' && $parts[2] !== 'HTTP/1.1') {
            throw new \RuntimeException('Invalid HTTP version');
        }
        
        $request = new HttpRequest($parts[1], $parts[0], [], \substr($parts[2], -3));
        
        while (NULL !== ($line = yield $stream->readLine())) {
            if (\trim($line) === '') {
                break;
            }
            
            $parts = \explode(':', $line, 2);
            
            $request = $request->withAddedHeader(\trim($parts[0]), \trim($parts[1]));
        }
        
        $body = Http1Body::fromMessage($stream, $request);
        $body->setCascadeClose(false);
        
        if ($request->isContinueExpected()) {
            $body->setExpectContinue($stream);
        }
        
        return $request->withBody($body);
    }
    
    protected function sendResponse(DuplexStream $stream, HttpRequest $request, HttpResponse $response, bool $close): \Generator
    {
        $input = yield $request->getBody()->getReadableStream();
        
        // Discard remaining request body before sending response.
        while (NULL !== yield $input->read());
        
        $http11 = ($response->getProtocolVersion() == '1.1');
        $body = $response->getBody();
        $size = yield $body->getSize();
        
        $buffer = Http::getStatusLine($response->getStatusCode(), 'HTTP/' . $request->getprotocolVersion()) . "\r\n";
        $buffer .= "Date: " . \gmdate(Http::DATE_RFC1123, \time()) . "\r\n";
        
        if ($close) {
            $buffer .= "Connection: close\r\n";
        }
        
        $accept = \array_map('trim', explode(',', \strtolower($request->getHeaderLine('Accept-Encoding'))));
        $compress = NULL;
        
        if (\function_exists('deflate_init')) {
            if (\in_array('gzip', $accept, true)) {
                $compress = \ZLIB_ENCODING_GZIP;
                $size = NULL;
                
                $buffer .= "Content-Encoding: gzip\r\n";
            } elseif (\in_array('x-gzip', $accept, true)) {
                $compress = \ZLIB_ENCODING_GZIP;
                $size = NULL;
                
                $buffer .= "Content-Encoding: x-gzip\r\n";
            } elseif (\in_array('deflate', $accept, true)) {
                $compress = \ZLIB_ENCODING_DEFLATE;
                $size = NULL;
                
                $buffer .= "Content-Encoding: deflate\r\n";
            }
        }
        
        $bodyStream = yield $body->getReadableStream();
        
        // HTTP/1.0 responses of unknown size are delimited by EOF / connection closed at the client's side.
        if ($http11 || $size !== NULL) {
            if ($size === NULL) {
                $buffer .= "Transfer-Encoding: chunked\r\n";
            } else {
                $buffer .= "Content-Length: $size\r\n";
            }
        }
        
        foreach ($response->getHeaders() as $name => $header) {
            $name = Http::normalizeHeaderName($name);
            
            foreach ($header as $value) {
                $buffer .= $name . ': ' . $value . "\r\n";
            }
        }
        
        $buffer .= "\r\n";
        
        yield $stream->write($buffer);
        yield $stream->flush();
        
        if ($compress !== NULL) {
            $stream = new WritableDeflateStream($stream, $compress);
        }
        
        if ($size === NULL) {
            // Align each chunk with length and line breaks to fit into 4 KB payload.
            yield new CopyBytes($bodyStream, $stream, true, null, 4089, function (string $chunk) {
                return \dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n";
            });
            
            yield $stream->write("0\r\n\r\n");
        } else {
            yield new CopyBytes($bodyStream, $stream, true, $size);
        }
        
        yield $stream->flush();
    }
}
