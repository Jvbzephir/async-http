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
    
    public function send(HttpRequest $request): \Generator
    {
        $uri = $request->getUri();
        $secure = ($uri->getScheme() === 'https');
        
        $host = $uri->getHost();
        $port = $uri->getPort() ?? ($secure ? Http::PORT_SECURE : Http::PORT);
        $options = $this->createStreamContextoptions();
        
        // TODO: Connectors need a way to re-use connections...
        
        $socket = yield from SocketStream::connect($host, $port, 'tcp', 5, $options);
    }
    
    protected function createStreamContextoptions(): array
    {
        $ssl = [];
        
        if (SocketStream::isAlpnSupported()) {
            $alpn = [];
            
            foreach ($this->connectors as $connector) {
                $alpn = array_merge($alpn, $connector->getProtocols());
            }
            
            $alpn = array_unique($alpn);
            $ssl['alpn_protocols'] = implode(' ', $alpn);
        }
        
        return [
            'ssl' => $ssl
        ];
    }
}
