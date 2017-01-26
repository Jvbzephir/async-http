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
use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpRequest;
use KoolKode\Async\Http\Logger;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * HTTP body implementation that incrementally generates contents as events are being sent.
 * 
 * @author Martin Schröder
 */
class EventBody extends DeferredBody implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    /**
     * Event source being used to generate events.
     * 
     * @var EventSource
     */
    protected $source;

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
        $this->logger = new Logger(static::class);
    }

    /**
     * {@inheritdoc}
     */
    public function start(HttpRequest $request)
    {
        $this->address = $request->getClientAddress();
        
        $this->logger->debug('Enabled SSE for {address} using HTTP/{version}', [
            'address' => $this->address,
            'version' => $request->getProtocolVersion()
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function close(bool $disconnected)
    {
        if ($disconnected) {
            $this->logger->debug('SSE client {address} disconnected', [
                'address' => $this->address
            ]);
        } else {
            $this->logger->debug('SSE source closed for client {address}', [
                'address' => $this->address
            ]);
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
