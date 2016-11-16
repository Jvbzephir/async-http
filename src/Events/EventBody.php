<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Events;

use KoolKode\Async\Http\Body\DeferredBody;
use KoolKode\Async\Http\HttpRequest;
use Psr\Log\LoggerInterface;

class EventBody extends DeferredBody
{
    protected $source;
    
    protected $logger;

    protected $address;
    
    public function __construct(EventSource $source)
    {
        $this->source = $source;
    }

    public function start(HttpRequest $request, LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        
        if ($this->logger) {
            $this->address = $request->getClientAddress();
            
            $this->logger->debug('Enabled SSE for {address} using HTTP/{version}', [
                'address' => $this->address,
                'version' => $request->getProtocolVersion()
            ]);
        }
    }

    public function close(bool $disconnected)
    {
        $this->source->close();
        
        if ($disconnected && $this->logger) {
            $this->logger->debug('SSE client {address} disconnected', [
                'address' => $this->address
            ]);
        }
    }

    protected function nextChunk(): \Generator
    {
        return yield $this->source->getChannel()->receive();
    }
}
