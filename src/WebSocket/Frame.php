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

class Frame
{
    const CONTINUATION = 0x00;

    const TEXT = 0x01;

    const BINARY = 0x02;

    const CONNECTION_CLOSE = 0x08;

    const PING = 0x09;

    const PONG = 0x0A;

    const FINISHED = 0b10000000;

    const OPCODE = 0b00001111;

    const MASKED = 0b10000000;

    const LENGTH = 0b01111111;

    /**
     * Indicates a normal closure, meaning that the purpose for which the connection was established has been fulfilled.
     * 
     * @var int
     */
    const NORMAL_CLOSURE = 1000;
    
    /**
     * Indicates that an endpoint is terminating the connection due to a protocol error.
     * 
     * @var int
     */
    const PROTOCOL_ERROR = 1002;

    /**
     * Indicates that an endpoint is terminating the connection because it has received data within a message that was not
     * consistent with the type of the message (e.g., non-UTF-8 data within a text message).
     * 
     * @var int
     */
    const INCONSISTENT_MESSAGE = 1007;

    /**
     * Indicates that an endpoint is terminating the connection because it has received a message that violates its policy.
     *
     * @var int
     */
    const POLICY_VIOLATION = 1008;

    /**
     * Indicates that an endpoint is terminating the connection because it has received a message that is too big for it to process.
     *
     * @var int
     */
    const MESSAGE_TOO_BIG = 1009;

    /**
     * Indicates that a server is terminating the connection because it encountered an unexpected condition that
     * prevented it from fulfilling the request.
     *
     * @var int
     */
    const UNEXPECTED_CONDITION = 1011;

    public $finished;

    public $opcode;

    public $data;

    public function __construct(int $opcode, string $data, bool $finished = true)
    {
        $this->finished = $finished;
        $this->opcode = $opcode;
        $this->data = $data;
    }

    public function __toString(): string
    {
        return \sprintf("%s [%s] %u bytes", $this->getOpcodeName(), $this->finished ? 'F' : 'C', \strlen($this->data));
    }

    public function __debugInfo(): array
    {
        $debug = \get_object_vars($this);
        $debug['name'] = $this->getOpcodeName();
        $debug['data'] = \sprintf('%u bytes', \strlen($this->data));
        
        return $debug;
    }

    public function encode(string $mask = null): string
    {
        $header = \chr(($this->finished ? self::FINISHED : 0) | $this->opcode);
        $mbit = ($mask === null) ? 0 : self::MASKED;
        $len = \strlen($this->data);
        
        if ($len > 0xFFFF) {
            $header .= \chr($mbit | 127) . \pack('NN', $len, $len << 32);
        } elseif ($len > 125) {
            $header .= \chr($mbit | 126) . \pack('n', $len);
        } else {
            $header .= \chr($mbit | $len);
        }
        
        if ($mask === null) {
            return $header . $this->data;
        }
        
        if (\strlen($mask) !== 4) {
            throw new ConnectionException(\sprintf('Mask must consist of 4 bytes, given %u bytes', \strlen($mask)));
        }
        
        return $header . $mask . ($this->data ^ \str_pad($mask, $len, $mask, \STR_PAD_RIGHT));
    }

    public function getOpcodeName(): string
    {
        switch ($this->opcode) {
            case self::CONTINUATION:
                return 'CONTINUATION';
            case self::TEXT:
                return 'TEXT';
            case self::BINARY:
                return 'BINARY';
            case self::CONNECTION_CLOSE:
                return 'CONNECTION CLOSE';
            case self::PING:
                return 'PING';
            case self::PONG:
                return 'PONG';
        }
        
        return '*UNKNOWN*';
    }

    public function isControlFrame(): bool
    {
        return $this->opcode >= self::CONNECTION_CLOSE;
    }
}
