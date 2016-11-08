<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Header;

use KoolKode\Util\MediaType;

/**
 * @covers \KoolKode\Async\Http\Header\ContentType
 */
class ContentTypeTest extends \PHPUnit_Framework_TestCase
{
    public function testCanCreateContentType()
    {
        $mediaType = new MediaType('text/html');
        
        $type = new ContentType($mediaType, [
            'charset' => 'utf-8'
        ]);
        
        $this->assertEquals($mediaType, $type->getMediaType());
        $this->assertEquals('utf-8', $type->getParam('charset'));
        $this->assertEquals($mediaType->getScore(), $type->getScore());
    }
}
