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

/**
 * Encapsulates a single WebSocket frame.
 *
 * @author Martin Schröder
 */
class Frame
{
    /**
     * Frame that carries fragmented data belonging to the most-recent text or binary frame.
     */
    public const CONTINUATION = 0x00;
    
    /**
     * Data frame that transfers a UTF-8 encoded text payload.
     */
    public const TEXT = 0x01;
    
    /**
     * Data frame that transfers arbitrary binary data.
     */
    public const BINARY = 0x02;
    
    /**
     * Control frame indicating that a connection is about to be closed.
     */
    public const CONNECTION_CLOSE = 0x08;
    
    /**
     * Control frame that instructs a peer to replay with a pong frame.
     */
    public const PING = 0x09;
    
    /**
     * Control frame that is received in response to a ping frame.
     */
    public const PONG = 0x0A;
    
    /**
     * Finished bit that indicates the end of a message.
     */
    public const FINISHED = 0b10000000;
    
    /**
     * Reserved bits to be used by extensions.
     */
    public const RESERVED = 0b01110000;
    
    /**
     * Mask being used to access reserved bit 1.
     */
    public const RESERVED1 = 0b01000000;
    
    /**
     * Mask being used to access reserved bit 2.
     */
    public const RESERVED2 = 0b00100000;
    
    /**
     * Mask being used to access reserved bit 3.
     */
    public const RESERVED3 = 0b00010000;
    
    /**
     * Mask being used to read the opcode of a frame.
     */
    public const OPCODE = 0b00001111;
    
    /**
     * Masked bit being used to indicate a masked frame sent by a client.
     */
    public const MASKED = 0b10000000;
    
    /**
     * Mask being used to read the first length byte.
     */
    public const LENGTH = 0b01111111;
    
    /**
     * Indicates a normal closure, meaning that the purpose for which the connection was established has been fulfilled.
     */
    public const NORMAL_CLOSURE = 1000;
    
    /**
     * Indicates that an endpoint is terminating the connection due to a protocol error.
     */
    public const PROTOCOL_ERROR = 1002;
    
    /**
     * Indicates that an endpoint is terminating the connection because it has received data within a message that was not
     * consistent with the type of the message (e.g., non-UTF-8 data within a text message).
     */
    public const INCONSISTENT_MESSAGE = 1007;
    
    /**
     * Indicates that an endpoint is terminating the connection because it has received a message that violates its policy.
     */
    public const POLICY_VIOLATION = 1008;
    
    /**
     * Indicates that an endpoint is terminating the connection because it has received a message that is too big for it to process.
     */
    public const MESSAGE_TOO_BIG = 1009;
    
    /**
     * Indicates that a server is terminating the connection because it encountered an unexpected condition that
     * prevented it from fulfilling the request.
     */
    public const UNEXPECTED_CONDITION = 1011;
    
    /**
     * Opcode of the frame.
     *
     * @var int
     */
    public $opcode;
    
    /**
     * Finished flag (indicates the end of a message).
     *
     * @var bool
     */
    public $finished;
    
    /**
     * Reserved bit flags.
     *
     * @var int
     */
    public $reserved;
    
    /**
     * Frame payload (masked frames must be unmasked before setting data).
     *
     * @var string
     */
    public $data;
    
    /**
     * Crate a new WebSocket frame.
     *
     * @param int $opcode
     * @param string $data
     * @param bool $finished
     */
    public function __construct(int $opcode, string $data, bool $finished = true, int $reserved = 0)
    {
        $this->finished = $finished;
        $this->opcode = $opcode;
        $this->data = $data;
        $this->reserved = $reserved;
    }
    
    /**
     * Display meta data and length of the frame.
     */
    public function __toString(): string
    {
        return \sprintf("%s [%s] %u bytes", $this->getOpcodeName(), $this->finished ? 'F' : 'C', \strlen($this->data));
    }
    
    /**
     * Dump frame in a human-readable way.
     */
    public function __debugInfo(): array
    {
        $debug = \get_object_vars($this);
        $debug['name'] = $this->getOpcodeName();
        $debug['data'] = \sprintf('%u bytes', \strlen($this->data));
        
        return $debug;
    }
    
    /**
     * Encode the given frame for transmission.
     *
     * @param string $mask Optional random mask to be applied to the frame (length must be 4 bytes exactly).
     * @return string
     */
    public function encode(?string $mask = null): string
    {
        $header = \chr(($this->finished ? self::FINISHED : 0) | $this->reserved | $this->opcode);
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
            throw new \InvalidArgumentException(\sprintf('Mask must consist of 4 bytes, given %u bytes', \strlen($mask)));
        }
        
        return $header . $mask . ($this->data ^ \str_pad($mask, $len, $mask, \STR_PAD_RIGHT));
    }
    
    /**
     * Get a human-readable name for the frame's opcode.
     */
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
    
    /**
     * Check if the frame is a control frame.
     *
     * @return bool
     */
    public function isControlFrame(): bool
    {
        return $this->opcode >= self::CONNECTION_CLOSE;
    }
}
