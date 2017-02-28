<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Body;

use KoolKode\Async\Test\AsyncTestCase;

/**
 * @covers \KoolKode\Async\Http\Body\DeferredBody
 */
class DeferredBodyTest extends AsyncTestCase
{
    public function testCanAccessBodyContents()
    {
        $body = new class() extends DeferredBody {

            public $disconnected = false;

            public $chunk = 0;

            public function close(bool $disconnected)
            {
                $this->disconnected = $disconnected;
            }

            protected function nextChunk(): \Generator
            {
                if ($this->chunk > 2) {
                    return;
                }
                
                yield null;
                
                return (string) ++$this->chunk;
            }
        };
        
        $this->assertFalse($body->isCached());
        $this->assertNull(yield $body->getSize());
        $this->assertEquals('123', yield $body->getContents());
        
        $body->close(true);
        $this->assertTrue($body->disconnected);
    }

    public function testCanDiscardBody()
    {
        $body = new class() extends DeferredBody {
            
            public function close(bool $disconnected) { }

            protected function nextChunk(): \Generator
            {
                return yield null;
            }
        };
        
        $this->assertEquals(0, yield $body->discard());
    }
}
