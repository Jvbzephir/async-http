<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Test;

use KoolKode\Async\Http\Http1\Connector as Http1Connector;
use KoolKode\Async\Http\Http1\Driver as Http1Driver;
use KoolKode\Async\Http\Http2\Connector as Http2Connector;
use KoolKode\Async\Http\Http2\Driver as Http2Driver;
use KoolKode\Async\Http\WebSocket\ConnectionHandler;
use KoolKode\Async\Test\AsyncTestCase;

/**
 * Base class for HTTP end-to-end testing using an HttpClient and an HttpEndpoint.
 * 
 * @author Martin Schröder
 */
abstract class EndToEndTest extends AsyncTestCase
{
    /**
     * HTTP test client.
     * 
     * @var HttpTestClient
     */
    protected $httpClient;

    /**
     * HTTP test server.
     * 
     * @var HttpTestEndpoint
     */
    protected $httpServer;

    /**
     * Setup HTTP client / server.
     */
    protected function setUp()
    {
        parent::setUp();
        
        $this->httpServer = new HttpTestEndpoint($this->getDrivers(), 'localhost:1337', $this->isEncrypted());
        $this->httpClient = new HttpTestClient($this->getConnectors(), $this->httpServer);
    }
    
    /**
     * Dispose client / server tasks after test coroutine has finished.
     */
    protected function disposeTest()
    {
        $this->httpClient->shutdown();
    }

    /**
     * Get base URI of the HTTP test server.
     */
    protected function getBaseUri(): string
    {
        return \sprintf('%s://localhost:1337/', $this->isEncrypted() ? 'https' : 'http');
    }

    /**
     * Determine if a TLS-encrypted socket should be simulated.
     */
    protected function isEncrypted(): bool
    {
        return true;
    }

    /**
     * Get all HTTP connectors to be used by the test.
     */
    protected function getConnectors(): array
    {
        $http1 = new Http1Connector();
        
        $http2 = new Http2Connector();
        
        return [
            $http2,
            $http1
        ];
    }

    /**
     * Get all HTTP drivers to be used by the test.
     */
    protected function getDrivers(): array
    {
        $http2 = new Http2Driver();
        
        $ws = new ConnectionHandler();
        $ws->setDeflateSupported(true);
        
        $http1 = new Http1Driver();
        $http1->setDebug(true);
        
        $http1->addUpgradeHandler($http2);
        $http1->addUpgradeResultHandler($ws);
        
        return [
            $http2,
            $http1
        ];
    }
}
