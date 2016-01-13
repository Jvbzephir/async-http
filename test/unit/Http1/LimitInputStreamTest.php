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

use KoolKode\Async\Test\AsyncTrait;

use function KoolKode\Async\tempStream;

class LimitInputStreamTest extends \PHPUnit_Framework_TestCase
{
    use AsyncTrait;
    
    public function testCanDecodeCompressedData()
    {
        $executor = $this->createExecutor();
        
        $executor->runCallback(function () {
            $data = 'FOO & BAR';
            $in = new LimitInputStream(yield tempStream($data), 5);
            
            $this->assertFalse($in->eof());
            $this->assertEquals('FOO &', yield from $this->readContents($in));
            $this->assertTrue($in->eof());
        });
        
        $executor->run();
    }
}
