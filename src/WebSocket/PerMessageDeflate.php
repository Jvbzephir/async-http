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

use KoolKode\Async\Http\HeaderToken;

/**
 * Implements settings and shared context for the RFC 7692 permessage-deflate extension.
 * 
 * @link https://tools.ietf.org/html/rfc7692
 * 
 * @author Martin Schröder
 */
class PerMessageDeflate
{
    /**
     * Shared zlib compression context.
     * 
     * @var resource
     */
    protected $compression;
    
    /**
     * Use shared compression context for multiple messages.
     * 
     * @var bool
     */
    protected $compressionTakeover;

    /**
     * Compression window size exponent (2^X).
     * 
     * @var int
     */
    protected $compressionWindow;
    
    /**
     * shared zlib decompression context.
     * 
     * @var resource
     */
    protected $decompression;

    /**
     * Use shared decompression context for multiple messages.
     * 
     * @var bool
     */
    protected $decompressionTakeover;
    
    /**
     * Decompression window size exponent (2^X).
     * 
     * @var int
     */
    protected $decompressionWindow;

    /**
     * Create a new permessage-defalte extension handler.
     * 
     * @param bool $compressionTakeover Use shared compression context for multiple messages?
     * @param bool $decompressionTakeover Use shared decompression context for multiple messages?
     * @param int $compressionWindow Compression window size exponent (2^X).
     * @param int $decompressionWindow  Decompression window size exponent (2^X).
     */
    public function __construct(bool $compressionTakeover, bool $decompressionTakeover, int $compressionWindow = 15, int $decompressionWindow = 15)
    {
        $this->compressionTakeover = $compressionTakeover;
        $this->compressionWindow = $compressionWindow;
        $this->decompressionTakeover = $decompressionTakeover;
        $this->decompressionWindow = $decompressionWindow;
    }
    
    /**
     * Create a permessage-deflate extension using the settings specified in the given header token.
     * 
     * @param HeaderToken $token
     * @return PerMessageDeflate
     * 
     * @throws \OutOfRangeException When the compression / decompression window exponent is not between 8 and 15.
     */
    public static function fromHeaderToken(HeaderToken $token): PerMessageDeflate
    {
        $compressionTakeover = $token->getParam('client_no_context_takeover', true) ? true : false;
        $decompressionTakeover = $token->getParam('server_no_context_takeover', true) ? true : false;
        $compressionWindow = (int) $token->getParam('client_max_window_bits', 15);
        $decompressionWindow = (int) $token->getParam('server_max_window_bits', 15);
        
        if ($compressionWindow < 8 || $compressionWindow > 15) {
            throw new \OutOfRangeException(\sprintf('Client window size must be between 8 and 15, given %s', $compressionWindow));
        }
        
        if ($decompressionWindow < 8 || $decompressionWindow > 15) {
            throw new \OutOfRangeException(\sprintf('Server window size must be between 8 and 15, given %s', $decompressionWindow));
        }
        
        return new PerMessageDeflate($compressionTakeover, $decompressionTakeover, $compressionWindow, $decompressionWindow);
    }
    
    /**
     * Get negotiated extension header to be sent as an HTTP header in a WebSocket handshake message.
     * 
     * @return string
     */
    public function getExtensionHeader(): string
    {
        $ext = 'permessage-deflate';
        
        if (!$this->compressionTakeover) {
            $ext .= ';client_no_context_takeover';
        }
        
        if (!$this->decompressionTakeover) {
            $ext .= ';server_no_context_takeover';
        }
        
        $ext .= ';client_max_window_bits=' . $this->compressionWindow;
        $ext .= ';server_max_window_bits=' . $this->decompressionWindow;
        
        return $ext;
    }

    /**
     * Get zlib compression flush mode for the final chunk of a message.
     * 
     * @return int
     */
    public function getCompressionFlushMode(): int
    {
        return $this->compressionTakeover ? \ZLIB_SYNC_FLUSH : \ZLIB_FINISH;
    }
    
    /**
     * Get or create the zlib compression context to be used.
     * 
     * @return resource
     */
    public function getCompressionContext()
    {
        if ($this->compression) {
            return $this->compression;
        }
        
        $context = \deflate_init(\ZLIB_ENCODING_RAW, [
            'level' => 1,
            'window' => $this->compressionWindow
        ]);
        
        if ($this->compressionTakeover) {
            return $this->compression = $context;
        }
        
        return $context;
    }
    
    /**
     * Get zlib decompression flush mode for the final chunk of a message.
     *
     * @return int
     */
    public function getDecompressionFlushMode(): int
    {
        return $this->decompressionTakeover ? \ZLIB_SYNC_FLUSH : \ZLIB_FINISH;
    }

    /**
     * Get or create the zlib decompression context to be used.
     *
     * @return resource
     */
    public function getDecompressionContext()
    {
        if ($this->decompression) {
            return $this->decompression;
        }
        
        $context = \inflate_init(\ZLIB_ENCODING_RAW);
        
        if ($this->decompressionTakeover) {
            return $this->decompression = $context;
        }
        
        return $context;
    }
}
