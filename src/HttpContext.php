<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

use KoolKode\Util\InvalidMediaTypeException;
use KoolKode\Util\MediaType;
use KoolKode\Async\Http\Http2\HPackContext;

/**
 * HTTP context options and settings.
 * 
 * @author Martin Schröder
 */
class HttpContext
{
    /**
     * Media types that should be compresseed.
     * 
     * @var array
     */
    protected $compressedTypes = [];
    
    /**
     * Media subtypes that should be compressed.
     * 
     * @var array
     */
    protected $compressedSubTypes = [
        'html' => true,
        'css' => true,
        'xml' => true,
        'json' => true,
        'javascript' => true,
        'ecmascript' => true
    ];
    
    /**
     * HTTP/2 HPACK context.
     * 
     * @var HPackContext
     */
    protected $hpackContext;
    
    /**
     * Create a new HTTP context.
     * 
     * @param HPackContext $hpackContext HTTP/2 HPACK context.
     */
    public function __construct(HPackContext $hpackContext = NULL)
    {
        $this->hpackContext = $hpackContext;
    }
    
    /**
     * Add a media type that should be compressed.
     * 
     * @param mixed $type Must be a valid media type or media type pattern.
     */
    public function addCompressedType($type)
    {
        $this->compressedTypes[] = new MediaType($type);
    }
    
    /**
     * Add a media subtype that should be compressed.
     * 
     * @param string $sub
     */
    public function addCompressedSubType(string $sub)
    {
        $this->compressedSubTypes[$sub] = true;
    }
    
    /**
     * Check if the given HTTP message should be compressed before data is sent.
     *
     * @param HttpMessage $message
     * @return bool
     */
    public function isCompressible(HttpMessage $message): bool
    {
        if (!$message instanceof HttpResponse || !$message->hasHeader('Content-Type')) {
            return false;
        }
    
        try {
            $mediaType = new MediaType($message->getHeaderLine('Content-Type', 'application/octet-stream'));
        } catch (InvalidMediaTypeException $e) {
            return false;
        }
    
        foreach ($mediaType->getSubTypes() as $sub) {
            if (!empty($this->compressedSubTypes[$sub])) {
                return true;
            }
        }
    
        return false;
    }
    
    /**
     * Get the HPACK context to be used by HTTP/2.
     */
    public function getHpackContext(): HPackContext
    {
        if ($this->hpackContext === NULL) {
            $this->hpackContext = HPackContext::getDefaultContext();
        }
        
        return $this->hpackContext;
    }
}
