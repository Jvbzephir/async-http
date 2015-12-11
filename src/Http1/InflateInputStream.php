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

use function KoolKode\Async\noop;

/**
 * Decompresses output as it is being read.
 * 
 * @author Martin Schröder
 */
class InflateInputStream implements InputStreamInterface
{
    const RAW = ZLIB_ENCODING_RAW;

    const DEFLATE = ZLIB_ENCODING_DEFLATE;

    const GZIP = ZLIB_ENCODING_GZIP;

    const MIN_BUFFER_SIZE = 65536;

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
     * Error handler callback.
     *
     * @var callable
     */
    protected static $errorHandler;

    /**
     * Decompress data as it is being read from the given input stream.
     * 
     * @param StreamInterface $stream
     * @param int $encoding
     * 
     * @throws \RuntimeException When decompression is not supported by the installed PHP version.
     * @throws \InvalidArgumentException When an invalid compression encoding is specified.
     */
    public function __construct(InputStreamInterface $stream, $encoding = self::DEFLATE)
    {
        if (!function_exists('inflate_init')) {
            throw new \RuntimeException('Stream decompression requires PHP 7');
        }
        
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
        $this->context = $this->invokeWithErrorHandler('inflate_init', $encoding);
    }

    /**
     * Assemble debug data.
     * 
     * @return array
     */
    public function __debugInfo()
    {
        $info = get_object_vars($this);
        $info['buffer'] = sprintf('%u bytes buffered', strlen($info['buffer']));
        
        return $info;
    }

    public function close()
    {
        $this->buffer = '';
        $this->context = NULL;
        $this->finished = true;
        
        if ($this->strean !== NULL) {
            try {
                $this->strean->close();
            } finally {
                $this->strean = NULL;
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
            throw new \RuntimeException(sprintf('Cannot read from detached stream'));
        }
        
        if ($this->finished || $length == 0) {
            return '';
        }
        
        if ($this->buffer === '') {
            if ($this->stream->eof()) {
                $this->buffer = $this->invokeWithErrorHandler('inflate_add', $this->context, '', ZLIB_FINISH);
                $this->finished = true;
            } else {
                do {
                    $chunk = yield from $this->stream->read(max($length, self::MIN_BUFFER_SIZE), $timeout);
                    
                    if ($chunk !== '') {
                        $this->buffer .= $this->invokeWithErrorHandler('inflate_add', $this->context, $chunk);
                        
                        if ($this->buffer !== '') {
                            break;
                        }
                    }
                } while (!$this->stream->eof());
            }
        }
        
        $chunk = (string) substr($this->buffer, 0, $length);
        $len = strlen($chunk);
        
        $this->buffer = (string) substr($this->buffer, $len);
        
        return $chunk;
    }
    
    /**
     * Initialize and return the error handler callback.
     *
     * @return callable
     */
    protected static function handleError()
    {
        if(self::$errorHandler === NULL)
        {
            self::$errorHandler = function ($type, $message, $file, $line) {
                throw new \RuntimeException($message);
            };
        }
    
        return self::$errorHandler;
    }
    
    /**
     * Invoke the given callback handling all errors / warnings using exceptions.
     *
     * @param callable $callback Callback to be invoked.
     * @param mixed ...$args Optional arguments to passed to the callback.
     * @return mixed Whatever is returned by the callback.
     */
    protected static function invokeWithErrorHandler(callable $callback, ...$args)
    {
        if(self::$errorHandler === NULL)
        {
            self::$errorHandler = function ($type, $message, $file, $line) {
                throw new \RuntimeException($message);
            };
        }
    
        set_error_handler(self::$errorHandler);
    
        try
        {
            return $callback(...$args);
        }
        finally
        {
            restore_error_handler();
        }
    }
}
