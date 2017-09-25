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

namespace KoolKode\Async\Http\Http2;

use KoolKode\Async\Context;
use KoolKode\Async\Promise;
use KoolKode\Async\Stream\DuplexStream;

class ClientConnectionFactory
{
    protected $hpack;

    protected $localSettings = [
        Connection::SETTING_ENABLE_PUSH => 0,
        Connection::SETTING_MAX_CONCURRENT_STREAMS => 256,
        Connection::SETTING_INITIAL_WINDOW_SIZE => 0xFFFF,
        Connection::SETTING_MAX_FRAME_SIZE => 16384
    ];
    
    protected $remoteSettings = [
        Connection::SETTING_HEADER_TABLE_SIZE => 4096,
        Connection::SETTING_ENABLE_PUSH => 1,
        Connection::SETTING_MAX_CONCURRENT_STREAMS => 100,
        Connection::SETTING_INITIAL_WINDOW_SIZE => 0xFFFF,
        Connection::SETTING_MAX_FRAME_SIZE => 16384,
        Connection::SETTING_MAX_HEADER_LIST_SIZE => 16777216
    ];
    
    public function __construct(?HPackContext $hpack = null)
    {
        $this->hpack = $hpack ?? new HPackClientContext();
    }

    public function connectClient(Context $context, DuplexStream $stream): Promise
    {
        return $context->task(function (Context $context) use ($stream) {
            try {
                yield $stream->write($context, Connection::PREFACE);
                
                $stream = new FramedStream($stream, $stream);
                
                $settings = '';
                
                foreach ($this->localSettings as $k => $v) {
                    $settings .= \pack('nN', $k, $v);
                }
                
                yield $stream->writeFrame($context, new Frame(Frame::SETTINGS, 0, $settings));
                yield $stream->writeFrame($context, new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', 0x0FFFFFFF)));
                
                $frame = yield $stream->readFrame($context);
                
                if ($frame->stream !== 0 || $frame->type !== Frame::SETTINGS) {
                    throw new ConnectionException('Failed to establish HTTP/2 connection');
                }
                
                // TODO: Apply initial settings.
            } catch (\Throwable $e) {
                $stream->close();
                
                throw $e;
            }
            
            return new Connection($context->getLoop(), Connection::CLIENT, $stream, new HPack($this->hpack));
        });
    }
}
