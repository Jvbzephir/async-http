<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\WebSocket;

use KoolKode\Async\Test\AsyncTestCase;

class ClientTest extends AsyncTestCase
{
    public function testBasicEcho()
    {
        $client = new Client();
        
        $conn = yield $client->connect('ws://echo.websocket.org/');
        
        $this->assertTrue($conn instanceof Connection);
        
        try {
            yield $conn->sendText('Hello World :)');
            yield $conn->sendText('Echo!');
            
            $this->assertEquals('Hello World :)', yield $conn->readNextMessage());
            $this->assertEquals('Echo!', yield $conn->readNextMessage());
        } finally {
            $conn->shutdown();
        }
    }
}
