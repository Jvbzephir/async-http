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
 * @covers \KoolKode\Async\Http\HeaderToken
 */
class HeaderTokenTest extends \PHPUnit_Framework_TestCase
{
    public function testBasicToken()
    {
        $token = new HeaderToken('foo');
    
        $this->assertEquals('foo', $token->getValue());
        $this->assertEquals('foo', (string) $token);
    }
    
    public function testTokenWithParams()
    {
        $token = new HeaderToken('foo', [
            'bar' => true,
            'q' => 1
        ]);
        
        $this->assertEquals('foo', $token->getValue());
        $this->assertTrue($token->hasParam('bar'));
        $this->assertFalse($token->hasParam('baz'));
        $this->assertTrue($token->getParam('bar'));
        $this->assertEquals(1, $token->getParam('q'));
        $this->assertEquals('N/A', $token->getParam('baz', 'N/A'));
        $this->assertEquals('foo;bar;q=1', (string) $token);
    }
    
    public function testDetectsMissingParam()
    {
        $token = new HeaderToken('foo');
        
        $this->expectException(\OutOfBoundsException::class);
        
        $token->getParam('bar');
    }
    
    public function testCanModifyParams()
    {
        $token = new HeaderToken('foo');
        $token->setParam('bar', false);
        $this->assertFalse($token->getParam('bar'));
        $this->assertEquals('foo', (string) $token);
        
        $token->setParam('bar', null);
        $this->assertEquals('*', $token->getParam('bar', '*'));
        
        $token->setParam('type', 'text/html');
        $this->assertEquals('text/html', $token->getParam('type'));
        $this->assertEquals('foo;type="text/html"', (string) $token);
    }
    
    public function testDetectsInvalidParamValue()
    {
        $token = new HeaderToken('foo');
        
        $this->expectException(\InvalidArgumentException::class);
        
        $token->setParam('bar', []);
    }
    
    public function test()
    {
//         var_dump(HeaderToken::parse('foo;bar=.6'), HeaderToken::parseList('foo,bar;q=2, baz;name="Test"'));
    }
}
