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

/**
 * @covers \KoolKode\Async\Http\Http
 */
class HttpTest extends \PHPUnit_Framework_TestCase
{
    public function provideCodes()
    {
        yield [0, false, false, false];
        yield [100, true, false, false];
        yield [101, true, false, false];
        yield [299, true, false, false];
        yield [300, false, true, false];
        yield [399, false, true, false];
        yield [400, false, false, true];
        yield [400, false, false, true];
    }

    /**
     * @dataProvider provideCodes
     */
    public function testDetectsStatusType(int $status, bool $success, bool $redirect, bool $error)
    {
        $this->assertEquals($success, Http::isSuccess($status));
        $this->assertEquals($redirect, Http::isRedirect($status));
        $this->assertEquals($error, Http::isError($status));
    }
    
    public function provideStatusLines()
    {
        yield [Http::SEE_OTHER, 'HTTP/1.1 303 See Other'];
        yield [Http::NO_CONTENT, 'XXX 204 No Content', 'XXX'];
        yield [570, 'HTTP/1.1 570'];
    }

    /**
     * @dataProvider provideStatusLines
     */
    public function testStatusLine(int $status, string $line, string $protocol = NULL)
    {
        if ($protocol === NULL) {
            $this->assertEquals($line, Http::getStatusLine($status));
        } else {
            $this->assertEquals($line, Http::getStatusLine($status, $protocol));
        }
    }
    
    public function testGetReason()
    {
        $this->assertEquals('No Content', Http::getReason(Http::NO_CONTENT));
        $this->assertEquals('Fallback', Http::getReason(599, 'Fallback'));
    }
    
    public function testNormalizeHeaderNames()
    {
        $this->assertEquals('Foo', Http::normalizeHeaderName('foo'));
        $this->assertEquals('Foo-Bar', Http::normalizeHeaderName('foo-bar'));
    }
}
