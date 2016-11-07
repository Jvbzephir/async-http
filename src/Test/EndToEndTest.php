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
    protected $httpClient;

    protected $httpServer;

    protected function setUp()
    {
        parent::setUp();
        
        $this->httpServer = new HttpTestEndpoint($this->getDrivers(), 'localhost:1337', $this->isEncrypted());
        $this->httpClient = new HttpTestClient($this->getConnectors(), $this->httpServer);
    }
    
    protected function disposeTest()
    {
        $this->httpClient->shutdown();
    }

    protected function getBaseUri(): string
    {
        return \sprintf('%s://localhost:1337/', $this->isEncrypted() ? 'https' : 'http');
    }

    protected function isEncrypted(): bool
    {
        return true;
    }

    protected function getConnectors(): array
    {
        $http1 = new Http1Connector();
        
        $http2 = new Http2Connector();
        
        return [
            $http2,
            $http1
        ];
    }

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
