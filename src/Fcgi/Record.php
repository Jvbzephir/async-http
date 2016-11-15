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

namespace KoolKode\Async\Http\Fcgi;

/**
 * FCGI records are messages with headers, payload and padding that are transmitted and multiplexed over a single socket.
 * 
 * @author Martin Schröder
 * 
 * @link http://www.fastcgi.com/devkit/doc/fcgi-spec.html
 */
class Record
{
    /**
     * FastCGI protocol version 1.
     * 
     * @var int
     */
    const FCGI_VERSION_1 = 1;

    /**
     * The Web server sends a FCGI_BEGIN_REQUEST record to start a request.
     * 
     * @var int
     */
    const FCGI_BEGIN_REQUEST = 1;

    /**
     * The Web server sends a FCGI_ABORT_REQUEST record to abort a request.
     * 
     * After receiving {FCGI_ABORT_REQUEST, R}, the application responds as soon as possible with {FCGI_END_REQUEST, R, {FCGI_REQUEST_COMPLETE, appStatus}}.
     * This is truly a response from the application, not a low-level acknowledgement from the FastCGI library. 
     * 
     * @var int
     */
    const FCGI_ABORT_REQUEST = 2;

    /**
     * The application sends a FCGI_END_REQUEST record to terminate a request, either because the application has processed the request
     * or because the application has rejected the request. 
     * 
     * @var int
     */
    const FCGI_END_REQUEST = 3;

    /**
     * Stream record type used in sending name-value pairs from the Web server to the application.
     * The name-value pairs are sent down the stream one after the other, in no specified order.
     * 
     * @var int
     */
    const FCGI_PARAMS = 4;

    /**
     * Stream record type used in sending arbitrary data from the Web server to the application.
     * 
     * @var int
     */
    const FCGI_STDIN = 5;

    /**
     * Stream record type for sending arbitrary data from the application to the Web server.
     * 
     * @var int
     */
    const FCGI_STDOUT = 6;

    /**
     * Stream record type for sending error data from the application to the Web server.
     * 
     * @var int
     */
    const FCGI_STDERR = 7;

    /**
     * Stream record type used to send additional data to the application.
     * 
     * @var int
     */
    const FCGI_DATA = 8;

    /**
     * The application receives a query as a record {FCGI_GET_VALUES, 0, ...}.
     * The contentData portion of a FCGI_GET_VALUES record contains a sequence of name-value pairs with empty values.
     * 
     * @var int
     */
    const FCGI_GET_VALUES = 9;

    /**
     * The application responds by sending a record {FCGI_GET_VALUES_RESULT, 0, ...} with the values supplied.
     * If the application doesn't understand a variable name that was included in the query, it omits that name from the response. 
     * 
     * @var int
     */
    const FCGI_GET_VALUES_RESULT = 10;

    /**
     * FCGI version.
     * 
     * @var int
     */
    public $version;

    /**
     * FCGI record type.
     * 
     * @var int
     */
    public $type;

    /**
     * FCGI request ID.
     * 
     * @var int
     */
    public $requestId;

    /**
     * Record data without headers and padding.
     * 
     * @var string
     */
    public $data;

    /**
     * Create a new FCGI record.
     * 
     * @param int $version
     * @param int $type
     * @param int $requestId
     * @param string $data
     */
    public function __construct(int $version, int $type, int $requestId, string $data)
    {
        $this->version = $version;
        $this->type = $type;
        $this->requestId = $requestId;
        $this->data = $data;
    }

    public function __debugInfo(): array
    {
        return [
            'version' => $this->version,
            'type' => $this->type,
            'requestId' => $this->requestId,
            'data' => sprintf('%u bytes', \strlen($this->data))
        ];
    }
}
