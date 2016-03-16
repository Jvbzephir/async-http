<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

use KoolKode\Async\Http\Http1\Http1Connector;
use KoolKode\Async\Stream\SocketStream;
use Psr\Log\LoggerInterface;

/**
 * HTTP client that communicates using attached HTTP connectors.
 * 
 * @author Martin Schröder
 */
class HttpClient
{
    protected $logger;
    
    protected $connectors = [];
    
    public function __construct(LoggerInterface $logger = NULL)
    {
        $this->logger = $logger;
        
        $this->addConnector(new Http1Connector($logger));
    }
    
    public function shutdown()
    {
        foreach ($this->connectors as $connector) {
            $connector->shutdown();
        }
    }
    
    public function addConnector(HttpConnectorInterface $connector)
    {
        $this->connectors[] = $connector;
    }
    
    public function getHttp1Connector(): Http1Connector
    {
        foreach ($this->connectors as $connector) {
            if ($connector instanceof Http1Connector) {
                return $connector;
            }
        }
    }
    
    public function send(HttpRequest $request, array $contextOptions = []): \Generator
    {
        foreach ($this->connectors as $connector) {
            $context = $connector->getConnectorContext($request);
            
            if ($context !== NULL) {
                return yield from $connector->send($request, $context);
            }
        }
        
        $uri = $request->getUri();
        $secure = ($uri->getScheme() === 'https');
        
        $host = $uri->getHost();
        $port = $uri->getPort() ?? ($secure ? Http::PORT_SECURE : Http::PORT);
        $options = $this->createStreamContextoptions($contextOptions);
        
        $socket = yield from SocketStream::connect($host, $port, 'tcp', 5, $options);
        
        if ($secure) {
            yield from $socket->encrypt();
        }
        
        $context = new HttpConnectorContext($socket);
        
        if ($secure) {
            $protocol = $socket->getMetadata()['crypto']['alpn_protocol'] ?? '';
            
            foreach ($this->connectors as $connector) {
                if (in_array($protocol, $connector->getProtocols(), true)) {
                    return yield from $connector->send($request, $context);
                }
            }
        }
        
        return yield from $this->getHttp1Connector()->send($request, $context);
    }
    
    protected function createStreamContextoptions(array $contextOptions): array
    {
        $ssl = [];
        
        if (SocketStream::isAlpnSupported()) {
            $alpn = [];
            
            foreach ($this->connectors as $connector) {
                $alpn = array_merge($alpn, $connector->getProtocols());
            }
            
            $alpn = array_unique($alpn);
            
            if (!empty($alpn)) {
                $ssl['alpn_protocols'] = implode(' ', array_reverse($alpn));
            }
        }
        
        return array_replace_recursive($contextOptions, [
            'ssl' => $ssl
        ]);
    }
}
