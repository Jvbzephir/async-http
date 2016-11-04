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

class PerMessageDeflate
{
    protected $compression;
    
    protected $compressionTakeover;
    
    protected $compressionWindow;
    
    protected $decompression;

    protected $decompressionTakeover;

    public function __construct(bool $compressionTakeover, bool $decompressionTakeover, int $compressionWindow = 14)
    {
        $this->compressionTakeover = $compressionTakeover;
        $this->compressionWindow = $compressionWindow;
        $this->decompressionTakeover = $decompressionTakeover;
    }

    public function getCompressionFlushMode(): int
    {
        return $this->compressionTakeover ? \ZLIB_SYNC_FLUSH : \ZLIB_FINISH;
    }
    
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
    
    public function getDecompressionFlushMode(): int
    {
        return $this->decompressionTakeover ? \ZLIB_SYNC_FLUSH : \ZLIB_FINISH;
    }

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
