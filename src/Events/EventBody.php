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

/**
 * HTTP body implementation that incrementally generates contents as events are being sent.
 * 
 * @author Martin Schröder
 */
class EventBody extends DeferredBody
{
    /**
     * Event source being used to generate events.
     * 
     * @var EventSource
     */
    protected $source;
    
    /**
     * Optional PSR logger instance provided by an HTTP driver on start.
     * 
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Address of the HTTP client.
     * 
     * @var string
     */
    protected $address;
    
    /**
     * Create an HTTP body that can stream events from the given source.
     * 
     * @param EventSource $source
     */
    public function __construct(EventSource $source)
    {
        $this->source = $source;
    }

    /**
     * {@inheritdoc}
     */
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

    /**
     * {@inheritdoc}
     */
    public function close(bool $disconnected)
    {
        if ($this->logger) {
            if ($disconnected) {
                $this->logger->debug('SSE client {address} disconnected', [
                    'address' => $this->address
                ]);
            } else {
                $this->logger->debug('SSE source closed for client {address}', [
                    'address' => $this->address
                ]);
            }
        }
        
        $this->source->close();
    }

    /**
     * Each SSE event is streamed as a single chunk of data.
     */
    protected function nextChunk(): \Generator
    {
        return yield $this->source->getChannel()->receive();
    }
}
