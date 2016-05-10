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

use KoolKode\Async\Http\HttpMessage;

/**
 * The Accept-Encoding request-header field is similar to Accept, but restricts the content-codings that are acceptable in the response.
 * 
 * If an Accept-Encoding field is present in a request, and if the server cannot send a response which is acceptable according to the Accept-Encoding
 * header, then the server SHOULD send an error response with the 406 (Not Acceptable) status code.
 * 
 * @author Martin Schröder
 */
class AcceptEncodingHeader extends AbstractListHeader
{
    public function __construct(array $types = [])
    {
        $this->entries = $types;
    }
    
    public static function fromMessage(HttpMessage $message): AcceptEncodingHeader
    {
        $accept = $message->getHeaderLine('Accept-Encoding');
        $types = [];
        
        foreach (Attributes::splitValues($accept) as $str) {
            if (false === ($index = strpos($str, ';'))) {
                $type = new AcceptEncoding($str, [
                    'q' => 1.0
                ]);
            } else {
                $type = new AcceptEncoding(substr($str, 0, $index), array_merge([
                    'q' => 1.0
                ], Attributes::parseAttributes(substr($str, $index + 1))));
            }
            
            if (static::insertBasedOnQuality($type, $types)) {
                continue;
            }
            
            $types[] = $type;
        }
        
        return new static($types);
    }
    
    public function supportsIdentityEncoding(): bool
    {
        if (empty($this->entries)) {
            return true;
        }
        
        foreach ($this->entries as $encoding) {
            if ($encoding->getEncoding() == 'identity') {
                return true;
            }
        }
        
        return false;
    }
    
    public function getEncodings(): array
    {
        return $this->entries;
    }
}
