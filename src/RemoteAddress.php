<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http;

use KoolKode\Async\DNS\Address;

class RemoteAddress
{
    public $ip;
    
    public $port;
    
    public function __construct(string $ip, int $port)
    {
        $this->ip = (string) new Address($ip);
        $this->port = $port;
    }
}
