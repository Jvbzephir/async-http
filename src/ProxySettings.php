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
use KoolKode\Async\DNS\MalformedAddressException;

class ProxySettings
{
    protected $trustAllProxies = false;
    
    protected $trustedProxies = [];
    
    protected $schemeHeaders = [
        'X-Forwarded-Proto'
    ];
    
    protected $hostHeaders = [
        'X-Forwarded-Host'
    ];
    
    protected $addressHeaders = [
        'X-Forwarded-For'
    ];

    public function isTrustedProxy(string $address): bool
    {
        if ($this->trustAllProxies) {
            return true;
        }
        
        try {
            return isset($this->trustedProxies[(string) new Address($address)]);
        } catch (MalformedAddressException $e) {
            return false;
        }
    }
    
    public function addTrustedProxy(string ...$proxies)
    {
        foreach ($proxies as $proxy) {
            $this->trustedProxies[(string) new Address($proxy)] = true;
        }
    }

    public function addSchemeHeader(string $name)
    {
        $this->schemeHeaders[] = $name;
    }

    public function getScheme(HttpRequest $request)
    {
        foreach ($this->schemeHeaders as $name) {
            if ($request->hasHeader($name)) {
                return \strtolower($request->getHeaderTokens($name)[0]->getValue());
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
    
    public function addAddressHeader(string $name)
    {
        $this->addressHeaders = $name;
    }

    public function getAddresses(HttpRequest $request): array
    {
        $addresses = [];
        
        foreach ($this->addressHeaders as $name) {
            if ($request->hasHeader($name)) {
                foreach ($request->getHeaderTokenValues($name, false) as $ip) {
                    $addresses[] = $ip;
                }
            }
        }
        
        return $addresses;
    }
}
