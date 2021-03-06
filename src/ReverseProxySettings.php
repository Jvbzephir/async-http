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

/**
 * Configures HTTP reverse proxy support to be used by an HTTP endpoint / drivers.
 * 
 * @author Martin Schröder
 */
class ReverseProxySettings
{
    /**
     * Treat all remote peers as trusted HTTP proxies?
     * 
     * @var bool
     */
    protected $trustAllProxies = false;
    
    /**
     * Assembles trusted HTTP proxy IPs.
     * 
     * @var array
     */
    protected $trustedProxies = [];
    
    /**
     * HTTP headers that are allowed to override the HTTP scheme.
     * 
     * @var array
     */
    protected $schemeHeaders = [
        'X-Forwarded-Proto'
    ];
    
    /**
     * HTTP headers that are allowed to override the Host header.
     * 
     * @var array
     */
    protected $hostHeaders = [
        'X-Forwarded-Host'
    ];
    
    /**
     * HTTP headers that are being used to track client / proxy hops by address.
     * 
     * @var array
     */
    protected $addressHeaders = [
        'X-Forwarded-For'
    ];

    /**
     * Create a new HTTP reverse proxy configuration
     * 
     * @param string ...$proxies Set of trusted proxy IP addresses.
     */
    public function __construct(string ...$proxies)
    {
        foreach ($proxies as $proxy) {
            $this->trustedProxies[(string) new Address($proxy)] = true;
        }
    }

    /**
     * Check if the given IP address is a trusted HTTP proxy address.
     * 
     * @param string $address
     * @return bool
     */
    public function isTrustedProxy(string $address): bool
    {
        if ($this->trustAllProxies) {
            return true;
        }
        
        try {
            return isset($this->trustedProxies[(string) new Address($address)]);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }
    
    /**
     * Treat all remote peers as trusted HTTP proxies?
     * 
     * Warning: Only use this option if you are absolutely certain that every incoming HTTP request has been proxied by a trusted proxy!
     * 
     * @param bool $trust
     */
    public function withTrustAllProxies(bool $trust): self
    {
        $settings = clone $this;
        $settings->trustAllProxies = $trust;
        
        return $settings;
    }
    
    /**
     * Add trusted HTTP proxy address(es).
     * 
     * @param string ...$proxies
     */
    public function withTrustedProxy(string ...$proxies): self
    {
        $settings = clone $this;
        
        foreach ($proxies as $proxy) {
            $settings->trustedProxies[(string) new Address($proxy)] = true;
        }
        
        return $settings;
    }

    /**
     * Add an HTTP header that is allowed to override the HTTP request scheme.
     * 
     * @param string $name
     */
    public function withSchemeHeader(string $name): self
    {
        $settings = clone $this;
        $settings->schemeHeaders[] = $name;
        
        return $settings;
    }

    /**
     * Get the HTTP scheme as forwareded by a proxy.
     * 
     * @param HttpRequest $request
     * @return string Or null when no proxy header is found.
     */
    public function getScheme(HttpRequest $request): ?string
    {
        foreach ($this->schemeHeaders as $name) {
            if ($request->hasHeader($name)) {
                return \strtolower($request->getHeaderTokens($name)[0]->getValue());
            }
        }
        
        return null;
    }
    
    /**
     * Add an HTTP header that is allowed to override the HTTP Host header.
     * 
     * @param string $name
     */
    public function withHostHeader(string $name): self
    {
        $settings = clone $this;
        $settings->hostHeaders[] = $name;
        
        return $settings;
    }
    
    /**
     * Get the HTTP host as forwarded by a proxy.
     * 
     * @param HttpRequest $request
     * @return string Or null when no proxy header is found.
     */
    public function getHost(HttpRequest $request): ?string
    {
        foreach ($this->hostHeaders as $name) {
            if ($request->hasHeader($name)) {
                return $request->getHeaderTokens($name)[0]->getValue();
            }
        }
        
        return null;
    }
    
    /**
     * Add an HTTP header that is used to track client / proxy hop addresses.
     * 
     * @param string $name
     */
    public function withAddressHeader(string $name): self
    {
        $settings = clone $this;
        $settings->addressHeaders = $name;
        
        return $settings;
    }

    /**
     * Get all known hop IP addresses as provided by an HTTP proxy.
     * 
     * @param HttpRequest $request
     * @return array
     */
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
