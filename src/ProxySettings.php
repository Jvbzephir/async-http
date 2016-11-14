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

class ProxySettings
{
    protected $trustAllProxies = false;
    
    protected $trustedProxies = [
        '127.0.0.1' => true,
        '::1' => true
    ];
    
    protected $schemeHeaders = [
        'X-Forwarded-Proto'
    ];
    
    protected $hostHeaders = [
        'X-Forwarded-Host'
    ];

    public function isTrustedProxy(string $address): bool
    {
        if ($this->trustAllProxies) {
            return true;
        }
        
        return isset($this->trustedProxies[$address]);
    }
    
    public function addTrustedProxy(string $proxy)
    {
        $this->trustedProxies[\trim($proxy, '[]')] = true;
    }

    public function addSchemeHeader(string $name)
    {
        $this->schemeHeaders[] = $name;
    }

    public function getScheme(HttpRequest $request)
    {
        foreach ($this->schemeHeaders as $name) {
            if ($request->hasHeader($name)) {
                return $request->getHeaderTokens($name)[0]->getValue();
            }
        }
    }
    
    public function addHostHeader(string $name)
    {
        $this->hostHeaders[] = $name;
    }
    
    public function getHost(HttpRequest $request)
    {
        foreach ($this->hostHeaders as $name) {
            if ($request->hasHeader($name)) {
                return $request->getHeaderTokens($name)[0]->getValue();
            }
        }
    }
}
