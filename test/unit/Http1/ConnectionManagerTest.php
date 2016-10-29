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

use KoolKode\Async\Socket\Socket;
use KoolKode\Async\Socket\SocketStream;
use KoolKode\Async\Test\AsyncTestCase;
use KoolKode\Async\Http\Uri;
use KoolKode\Async\Deferred;

/**
 * @covers \KoolKode\Async\Http\Http1\ConnectionManager
 * @covers \KoolKode\Async\Http\Http1\ConnectorContext
 */
class ConnectionManagerTest extends AsyncTestCase
{
    public function testCanEnqueueConnections()
    {
        $manager = new ConnectionManager(8, 20, 60);
        $uri = Uri::parse('http://localhost/');
        
        $this->assertEquals(0, $manager->getConnectionCount($uri));
        $this->assertEquals(20, $manager->getMaxLifetime());
        $this->assertEquals(60, $manager->getMaxRequests());
        
        list ($a, $b) = Socket::createPair();
        
        $conn1 = yield $manager->getConnection($uri);
        $this->assertTrue($conn1 instanceof ConnectorContext);
        $this->assertFalse($conn1->connected);
        $this->assertEquals(60, $conn1->remaining);
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        $conn2 = yield $manager->getConnection($uri);
        $this->assertTrue($conn2 instanceof ConnectorContext);
        $this->assertFalse($conn2->connected);
        $this->assertEquals(2, $manager->getConnectionCount($uri));
        
        $conn1->socket = new SocketStream($a);
        $conn2->socket = new SocketStream($b);
        
        $manager->releaseConnection($uri, $conn1, 30, 5);
        $manager->releaseConnection($uri, $conn2, 10, 8);
        $this->assertEquals(2, $manager->getConnectionCount($uri));
        
        $this->assertTrue($conn1->connected);
        $this->assertEquals(5, $conn1->remaining);
        
        $this->assertTrue($conn2->connected);
        $this->assertEquals(8, $conn2->remaining);
        
        $this->assertSame($conn1, yield $manager->getConnection($uri));
        $this->assertSame($conn2, yield $manager->getConnection($uri));
        
        yield $manager->shutdown();
    }
    
    public function testWillLimitConnectionCount()
    {
        $manager = new ConnectionManager(1);
        $uri = Uri::parse('http://localhost/');
        
        $socks = Socket::createPair();
        
        $conn1 = yield $manager->getConnection($uri);
        $conn1->socket = new SocketStream($socks[0]);
        
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        $defer = $manager->getConnection($uri);
        $this->assertTrue($defer instanceof Deferred);
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        $manager->releaseConnection($uri, $conn1);
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        $this->assertSame($conn1, yield $defer);
        
        yield $manager->shutdown();
    }
    
    public function testWillDisposeExpiredConnection()
    {
        $manager = new ConnectionManager(1);
        $uri = Uri::parse('http://localhost/');
        
        $socks = Socket::createPair();
        
        $conn1 = yield $manager->getConnection($uri);
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        $conn1->socket = new SocketStream($socks[0]);
        
        $manager->releaseConnection($uri, $conn1);
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        Socket::shutdown($socks[1]);
        
        $conn2 = yield $manager->getConnection($uri);
        
        $this->assertTrue($conn2 instanceof ConnectorContext);
        $this->assertNotSame($conn1, $conn2);
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        yield $manager->shutdown();
    }

    public function testWillDisposeExpiredConnectionDuringRelease()
    {
        $manager = new ConnectionManager(1);
        $uri = Uri::parse('http://localhost/');
        
        $socks = Socket::createPair();
        
        $conn1 = yield $manager->getConnection($uri);
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        $conn1->socket = new SocketStream($socks[0]);
        
        $defer = $manager->getConnection($uri);
        $this->assertTrue($defer instanceof Deferred);
        
        Socket::shutdown($socks[1]);
        
        $manager->releaseConnection($uri, $conn1);
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        $conn2 = yield $defer;
        
        $this->assertTrue($conn2 instanceof ConnectorContext);
        $this->assertNotSame($conn1, $conn2);
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        yield $manager->shutdown();
    }

    public function testCanDisposeConnectionViaContext()
    {
        $manager = new ConnectionManager(1);
        $uri = Uri::parse('http://localhost/');
        
        $conn1 = yield $manager->getConnection($uri);
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        $defer = $manager->getConnection($uri);
        $this->assertTrue($defer instanceof Deferred);
        
        $conn1->dispose();
        
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        $conn2 = yield $defer;
        
        $this->assertTrue($conn2 instanceof ConnectorContext);
        $this->assertNotSame($conn1, $conn2);
        $this->assertEquals(1, $manager->getConnectionCount($uri));
        
        yield $manager->shutdown();
    }
}
