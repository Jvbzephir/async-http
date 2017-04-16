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

use PHPUnit\Framework\TestCase;

/**
 * @covers \KoolKode\Async\Http\Uri
 */
class UriTest extends TestCase
{
    public function testCanParseAllUriParts()
    {
        $uri = Uri::parse('https://user:password@test.me:1337/path/test/?foo=bar&test=1#baz');
        
        $this->assertEquals('https', $uri->getScheme());
        $this->assertEquals('user:password', $uri->getUserInfo());
        $this->assertEquals('user:password@test.me:1337', $uri->getAuthority());
        $this->assertEquals('test.me', $uri->getHost());
        $this->assertEquals(1337, $uri->getPort());
        $this->assertEquals('test.me:1337', $uri->getHostWithPort());
        $this->assertEquals('test.me:1337', $uri->getHostWithPort(true));
        $this->assertEquals('/path/test/', $uri->getPath());
        $this->assertEquals('foo=bar&test=1', $uri->getQuery());
        $this->assertEquals('baz', $uri->getFragment());
    }
    
    public function testParseDetectsUriObject()
    {
        $uri = new Uri();
        
        $this->assertSame($uri, Uri::parse($uri));
    }
    
    public function testParseUriFail()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        Uri::parse('a://://');
    }
    
    public function provideUrisToBeSerialized()
    {
        yield ['/'];
        yield ['http://test.me/'];
        yield ['http://user@test.me/'];
        yield ['http://user:pw@test.me/'];
        yield ['/test?foo=bar'];
        yield ['/test#anchor'];
    }
    
    /**
     * @dataProvider provideUrisToBeSerialized
     */
    public function testCanSerializeUri(string $str)
    {
        $uri = Uri::parse($str);
        
        $this->assertEquals($str, (string) $uri);
        $this->assertEquals($str, json_decode(json_encode($uri)));
    }
    
    public function testCanMutateScheme()
    {
        $uri = Uri::parse('http://test.me/');
        
        $this->assertNotSame($uri, $uri = $uri->withScheme('https'));
        $this->assertEquals('https', $uri->getScheme());
        
        $this->assertSame($uri, $uri->withScheme('https'));
    }
    
    public function testDetectsUnsupportedSchema()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        new Uri('ftp');
    }
    
    public function testCanMutateUserInfo()
    {
        $uri = Uri::parse('http://test.me/');
        
        $this->assertNotSame($uri, $uri = $uri->withUserInfo('test'));
        $this->assertEquals('test', $uri->getUserInfo());
        
        $this->assertNotSame($uri, $uri = $uri->withUserInfo('test', 'pw'));
        $this->assertEquals('test:pw', $uri->getUserInfo());
        
        $this->assertNotSame($uri, $uri = $uri->withUserInfo(''));
        $this->assertEquals('', $uri->getUserInfo());
    }
    
    public function testDetectsEmptyHostWithPort()
    {
        $this->assertEquals('', (new Uri())->getHostWithPort());
    }

    public function testCanMutateHostAndPort()
    {
        $uri = Uri::parse('http://test.me/');
        
        $this->assertNotSame($uri, $uri = $uri->withHost('localhost'));
        $this->assertEquals('localhost', $uri->getHost());
        $this->assertnull($uri->getPort());
        $this->assertEquals('localhost', $uri->getHostWithPort());
        $this->assertEquals('localhost:80', $uri->getHostWithPort(true));
        
        $this->assertNotSame($uri, $uri = $uri->withHost('localhost:80'));
        $this->assertEquals('localhost', $uri->getHost());
        $this->assertEquals(80, $uri->getPort());
        $this->assertEquals('localhost', $uri->getHostWithPort());
        $this->assertEquals('localhost:80', $uri->getHostWithPort(true));
        
        $this->assertNotSame($uri, $uri = $uri->withHost('localhost:88'));
        $this->assertEquals('localhost', $uri->getHost());
        $this->assertEquals(88, $uri->getPort());
        $this->assertEquals('localhost:88', $uri->getHostWithPort());
    }

    public function testCanMutateHostAndPortOfSecureUri()
    {
        $uri = Uri::parse('https://test.me/');
        
        $this->assertNotSame($uri, $uri = $uri->withHost('localhost'));
        $this->assertEquals('localhost', $uri->getHost());
        $this->assertnull($uri->getPort());
        $this->assertEquals('localhost', $uri->getHostWithPort());
        $this->assertEquals('localhost:443', $uri->getHostWithPort(true));
        
        $this->assertNotSame($uri, $uri = $uri->withHost('localhost:443'));
        $this->assertEquals('localhost', $uri->getHost());
        $this->assertEquals(443, $uri->getPort());
        $this->assertEquals('localhost', $uri->getHostWithPort());
        $this->assertEquals('localhost:443', $uri->getHostWithPort(true));
    }
    
    public function testCanMutatePort()
    {
        $uri = Uri::parse('http://localhost/');
        $this->assertnull($uri->getPort());
        
        $this->assertNotSame($uri, $uri = $uri->withPort(88));
        $this->assertEquals(88, $uri->getPort());
        
        $this->assertNotSame($uri, $uri = $uri->withPort(null));
        $this->assertnull($uri->getPort());
    }
    
    public function provideInvalidPorts()
    {
        yield [0];
        yield [65536];
    }
    
    /**
     * @dataProvider provideInvalidPorts
     */
    public function testDetectsInvalidPorts(int $port)
    {
        $this->expectException(\InvalidArgumentException::class);
        
        (new Uri())->withPort($port);
    }
    
    public function testCanMutatePath()
    {
        $uri = Uri::parse('http://localhost/');
        $this->assertEquals('/', $uri->getPath());
        
        $this->assertNotSame($uri, $uri = $uri->withPath('foo'));
        $this->assertEquals('/foo', $uri->getPath());
    }
    
    public function testDetectsInvalidPath()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        (new Uri())->withPath('foo?');
    }

    public function testCanMutateQueryParams()
    {
        $uri = Uri::parse('http://localhost/');
        $this->assertEquals('', $uri->getQuery());
        $this->assertEquals([], $uri->getQueryParams());
        
        $this->assertSame($uri, $uri->withQuery(''));
        
        $this->assertNotSame($uri, $uri = $uri->withQuery('foo=bar'));
        $this->assertEquals('foo=bar', $uri->getQuery());
        $this->assertEquals([
            'foo' => 'bar'
        ], $uri->getQueryParams());
        
        $this->assertNotSame($uri, $uri = $uri->withQueryParams([
            'test' => 'yes'
        ]));
        $this->assertEquals('test=yes', $uri->getQuery());
    }
    
    public function testDetectsInvalidQuery()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        (new Uri())->withQuery('foo=bar#');
    }
    
    public function testCanAccessQueryParams()
    {
        $uri = Uri::parse('http://localhost/?foo=bar&test=yes');
        
        $this->assertTrue($uri->hasQueryParam('foo'));
        $this->assertFalse($uri->hasQueryParam('bar'));
        $this->assertTrue($uri->hasQueryParam('test'));
        
        $this->assertEquals('bar', $uri->getQueryParam('foo'));
        $this->assertEquals('baz', $uri->getQueryParam('bar', 'baz'));
        
        $this->assertNotSame($uri, $uri = $uri->withQuery('bar=baz'));        
        $this->assertEquals('baz', $uri->getQueryParam('bar'));
    }
    
    public function testDetectsMissingQueryParam()
    {
        $this->expectException(\OutOfBoundsException::class);
        
        (new Uri())->getQueryParam('foo');
    }
    
    public function testCanMutateFragment()
    {
        $uri = Uri::parse('http://localhost/test#foo');
        $this->assertEquals('foo', $uri->getFragment());
        
        $this->assertNotSame($uri, $uri = $uri->withFragment('bar'));
        $this->assertEquals('bar', $uri->getFragment());
        
        $this->assertNotSame($uri, $uri = $uri->withFragment(''));
        $this->assertEquals('', $uri->getFragment());
    }
    
    public function testCanEncodeUris()
    {
        $this->assertEquals('', Uri::encode(''));
        $this->assertEquals('foo', Uri::encode('foo'));
        $this->assertEquals('foo%2Bbar', Uri::encode('foo+bar'));
        $this->assertEquals('foo%2Fbar', Uri::encode('foo/bar'));
        $this->assertEquals('foo/bar', Uri::encode('foo/bar', false));
        $this->assertEquals('foo%20bar/baz', Uri::encode('foo bar/baz', false));
    }
    
    public function testCanDecodeUris()
    {
        $this->assertEquals('', Uri::decode(''));
        $this->assertEquals('foo', Uri::decode('foo'));
        $this->assertEquals('hello world', Uri::decode('hello%20world'));
        $this->assertEquals('hello world', Uri::decode('hello+world'));
    }
}
