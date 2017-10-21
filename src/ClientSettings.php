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

class ClientSettings
{
    protected $expectContinue = false;
    
    protected $expectContinueTimeout = 1000;

    public function isExpectContinue(): bool
    {
        return $this->expectContinue;
    }

    public function withExpectContinue(bool $expect): self
    {
        $settings = clone $this;
        $settings->expectContinue = $expect;
        
        return $settings;
    }

    public function getExpectContinueTimeout(): int
    {
        return $this->expectContinueTimeout;
    }

    public function withExpectContinueTimeout(int $timeout): self
    {
        $settings = clone $this;
        $settings->expectContinueTimeout = $timeout;
        
        return $settings;
    }
}
