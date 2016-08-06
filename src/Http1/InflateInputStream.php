<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http\Http1;

use KoolKode\Async\Stream\InputStreamInterface;
use KoolKode\Async\Stream\Stream;
use KoolKode\Async\Stream\StreamClosedException;

/**
 * Applies data decompression on top of another input stream.
 * 
 * @author Martin Schröder
 */
class InflateInputStream implements InputStreamInterface
{
    /**
     * ZLIB raw format, compatible with data produced by gzdeflate().
     * 
     * @var int
     */
    const RAW = ZLIB_ENCODING_RAW;

    /**
     * Deflate format, compatible with data produced by gzcompress().
     * 
     * @var int
     */
    const DEFLATE = ZLIB_ENCODING_DEFLATE;

    /**
     * GZIP format, compatible with data produced by gzencode().
     * 
     * @var int
     */
    const GZIP = ZLIB_ENCODING_GZIP;

    /**
     * Wrapped input stream that supplies compressed data.
     * 
     * @var InputStreamInterface
     */
    protected $stream;

    /**
     * Current read buffer.
     * 
     * @var string
     */
    protected $buffer = '';

    /**
     * Decompression context.
     * 
     * @var resource
     */
    protected $context;

    /**
     * Finished reading data?
     * 
     * @var bool
     */
    protected $finished = false;
    
    /**
     * Cascade the close operation to the wrapped stream?
     * 
     * @var bool
     */
    protected $cascadeClose;

    /**
     * Error handler callback.
     *
     * @var callable
     */
    protected static $errorHandler;

    /**
     * Decompress data as it is being read from the given input stream.
     * 
     * @param StreamInterface $stream Stream that supplies compressed data.
     * @param string $chunk First chunk of data.
     * @param int $encoding Expected compression encoding, use class constants of this class!
     * @param bool $cascadeClose Cascade the close operation to the wrapped stream?
     * 
     * @throws \InvalidArgumentException When an invalid compression encoding is specified.
     */
    public function __construct(InputStreamInterface $stream, string $chunk, $encoding = self::DEFLATE, bool $cascadeClose = true)
    {
        switch ($encoding) {
            case self::RAW:
            case self::DEFLATE:
            case self::GZIP:
                // OK
                break;
            default:
                throw new \InvalidArgumentException(sprintf('Invalid decompression ecncoding specified: %s', $encoding));
        }
        
        $this->stream = $stream;
        $this->cascadeClose = $cascadeClose;
        $this->context = $this->invokeWithErrorHandler('inflate_init', $encoding);
        
        $this->buffer = $this->invokeWithErrorHandler('inflate_add', $this->context, $chunk, $this->stream->eof() ? ZLIB_FINISH : ZLIB_NO_FLUSH);
        $this->finished = $this->stream->eof();
    }
    
    /**
     * Assemble debug data.
     * 
     * @return array
     */
    public function __debugInfo(): array
    {
        $info = get_object_vars($this);
        $info['buffer'] = sprintf('%u bytes buffered', \strlen($info['buffer']));
        
        return $info;
    }

    /**
     * Check if support for streaming decompression is available.
     * 
     * @return bool
     */
    public static function isAvailable(): bool
    {
        static $available;
        
        if ($available === NULL) {
            $available = function_exists('inflate_init');
        }
        
        return $available;
    }
    
    /**
     * Coroutine that creates an inflate input stream from the given stream.
     * 
     * @param InputStreamInterface $stream Stream that supplies compressed data.
     * @param int $encoding Expected compression encoding, use class constants of this class!
     * @param bool $cascadeClose Cascade the close operation to the wrapped stream?
     * @return InflateInputStream
     */
    public static function open(InputStreamInterface $stream, $encoding = self::DEFLATE, bool $cascadeClose = true): \Generator
    {
        return new static($stream, yield from Stream::readBuffer($stream, 8192), $encoding, $cascadeClose);
    }
    
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->buffer = '';
        $this->context = NULL;
        $this->finished = true;
        
        if ($this->stream !== NULL) {
            try {
                if ($this->cascadeClose) {
                    $this->stream->close();
                }
            } finally {
                $this->stream = NULL;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function eof(): bool
    {
        if ($this->stream === NULL) {
            return true;
        }
        
        return $this->buffer === '' && $this->finished;
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length = 8192, float $timeout = 0): \Generator
    {
        if ($this->stream === NULL) {
            throw new StreamClosedException('Cannot read from detached stream');
        }
        
        if ($this->finished && $this->buffer === '') {
            throw new StreamClosedException('Cannot read from terminated stream');
        }
        
        while ($this->buffer === '') {
            $chunk = yield from $this->stream->read(max($length, 8192), $timeout);
            
            if ($this->stream->eof()) {
                $this->finished = true;
                $this->buffer = $this->invokeWithErrorHandler('inflate_add', $this->context, $chunk, ZLIB_FINISH);
                $this->context = NULL;
                
                break;
            }
            
            $this->buffer = $this->invokeWithErrorHandler('inflate_add', $this->context, $chunk, ZLIB_NO_FLUSH);
        }
        
        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, \strlen($chunk));
        
        return $chunk;
    }
    
    /**
     * Invoke the given callback handling all errors / warnings using exceptions.
     *
     * @param callable $callback Callback to be invoked.
     * @param mixed ...$args Optional arguments to passed to the callback.
     * @return mixed Whatever is returned by the callback.
     */
    protected function invokeWithErrorHandler(callable $callback, ...$args)
    {
        if (self::$errorHandler === NULL) {
            self::$errorHandler = function ($type, $message, $file, $line) {
                throw new \ErrorException($message, 0, $type, $file, $line);
            };
        }
        
        set_error_handler(self::$errorHandler);
        
        try {
            return $callback(...$args);
        } finally {
            restore_error_handler();
        }
    }
}
