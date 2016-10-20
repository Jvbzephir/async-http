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

namespace KoolKode\Async\Http\WebSocket;

abstract class Endpoint
{
    public function onOpen(Connection $conn) { }
    
    public function onClose(Connection $conn) { }

    public function onTextMessage(Connection $conn, TextMessage $message) { }

    public function onBinaryMessage(Connection $conn, BinaryMessage $message)
    {
        $stream = $message->getStream();
        
        try {
            while (null !== (yield $stream->read()));
        } finally {
            $stream->close();
        }
    }

    public function onError(Connection $conn, \Throwable $e) { }
}
