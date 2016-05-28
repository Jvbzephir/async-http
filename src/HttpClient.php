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
use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketStream;
use Psr\Log\LoggerInterface;

/**
 * HTTP client that communicates using attached HTTP connectors.
 * 
 * @author Martin Schröder
 */
class HttpClient
{
    /**
     * PSR logger instance.
     * 
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * Registered HTTP connectors.
     * 
     * @var array
     */
    protected $connectors = [];
    
    /**
     * Create a new HTTP client.
     * 
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger = NULL)
    {
        $this->logger = $logger;
        
        $this->addConnector(new Http1Connector($logger));
    }
    
    /**
     * Shutdown will delegate the shutdown call to all registered connectors.
     */
    public function shutdown()
    {
        foreach ($this->connectors as $connector) {
            $connector->shutdown();
        }
    }
    
    /**
     * Attach an HTTP connector to the client.
     * 
     * @param HttpConnectorInterface $connector
     */
    public function addConnector(HttpConnectorInterface $connector)
    {
        $this->connectors[] = $connector;
    }
    
    /**
     * Get the default HTTP/1 connector.
     * 
     * @return Http1Connector
     */
    public function getHttp1Connector(): Http1Connector
    {
        foreach ($this->connectors as $connector) {
            if ($connector instanceof Http1Connector) {
                return $connector;
            }
        }
    }
    
    /**
     * Coroutine that sends an HTTP request using a connector and returns the response.
     * 
     * @param HttpRequest $request
     * @param array $contextOptions
     * @return HttpResponse
     */
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
            yield from $socket->encryptClient();
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
    
    /**
     * Assemble stream context options from given options and connector-specific options.
     * 
     * @param array $contextOptions
     * @return array
     */
    protected function createStreamContextoptions(array $contextOptions): array
    {
        $ssl = [];
        
        if (Socket::isAlpnSupported()) {
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
            'socket' => [
                'tcp_nodelay' => true
            ],
            'ssl' => $ssl
        ]);
    }
}
