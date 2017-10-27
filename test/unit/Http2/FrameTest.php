<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http2;

use PHPUnit\Framework\TestCase;

/**
 * @covers \KoolKode\Async\Http\Http2\Frame
 */
class FrameTest extends TestCase
{
    public function testCanConstructFrame()
    {
        $frame = new Frame(Frame::DATA, 3, 'Hello World', Frame::END_STREAM);
        
        $this->assertEquals(Frame::DATA, $frame->type);
        $this->assertEquals(3, $frame->stream);
        $this->assertEquals('Hello World', $frame->data);
        $this->assertEquals(Frame::END_STREAM, $frame->flags);
        
        $this->assertEquals('Hello World', $frame->getPayload());
        $this->assertEquals('DATA [1] <3> 11 bytes', (string) $frame);
    }
    
    public function provideFrameTypes()
    {
        yield [Frame::CONTINUATION, 'CONTINUATION'];
        yield [Frame::DATA, 'DATA'];
        yield [Frame::GOAWAY, 'GOAWAY'];
        yield [Frame::HEADERS, 'HEADERS'];
        yield [Frame::PING, 'PING'];
        yield [Frame::PRIORITY, 'PRIORITY'];
        yield [Frame::PUSH_PROMISE, 'PUSH_PROMISE'];
        yield [Frame::RST_STREAM, 'RST_STREAM'];
        yield [Frame::SETTINGS, 'SETTINGS'];
        yield [Frame::WINDOW_UPDATE, 'WINDOW_UPDATE'];
        yield [7464, 'UNKNOWN'];
    }
    
    /**
     * @dataProvider provideFrameTypes
     */
    public function testDetectsDifferentFrameTypes(int $type, string $label)
    {
        $frame = new Frame($type, 0, '');
        
        $this->assertEquals($label, $frame->getTypeName());
    }
    
    public function testCanGetPayloadWithoutPadding()
    {
        $frame = new Frame(Frame::DATA, 1, chr(3) . 'foobar', Frame::PADDED);
        
        $this->assertEquals(chr(3) . 'foobar', $frame->data);
        $this->assertEquals('foo', $frame->getPayload());
    }
    
    public function testCanEncodeFrame()
    {
        $data = chr(3) . 'foobar';
        $flags = Frame::PADDED | Frame::END_STREAM;
        
        $frame = new Frame(Frame::DATA, 7, $data, $flags);
        
        $this->assertEquals(substr(pack('NccN', strlen($data), Frame::DATA, $flags, 7), 1) . $data, $frame->encode());
    }
}
