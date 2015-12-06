<?php

/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

/**
 * Provides HTTP-related constants and methods.
 *
 * @author Martin Schröder
 * 
 * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html
 * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html
 */
abstract class Http
{
	/**
	 * Format of DateTime fields in HTTP headers.
	 * 
	 * @var string
	 */
	const DATE_FORMAT_RFC1123 = 'D, d M Y H:i:s \G\M\T';
	
	/**
	 * Format of expires directive in HTTP cookies.
	 * 
	 * @var string
	 */
	const DATE_COOKIE = 'D, d-M-Y H:i:s \G\M\T';
	
	/**
	 * Default port used by HTTP connections.
	 * 
	 * @var int
	 */
	const PORT = 80;
	
	/**
	 * Default port used by HTTPS connections.
	 * 
	 * @var int
	 */
	const PORT_SECURE = 443;
	
	/**
	 * MIME type of a urlencoded form entity.
	 * 
	 * @var string
	 */
	const FORM_ENCODED = 'application/x-www-form-urlencoded';
	
	/**
	 * MIME type of multipart encoded entity that may contain uploaded files.
	 * 
	 * @var string
	 */
	const FORM_MULTIPART_ENCODED = 'multipart/form-data';
	
	/**
	 * The HEAD method is identical to GET except that the server MUST NOT return a message-body in
	 * the response. The metainformation contained in the HTTP headers in response to a HEAD request
	 * SHOULD be identical to the information sent in response to a GET request. This method can be
	 * used for obtaining metainformation about the entity implied by the request without transferring
	 * the entity-body itself. This method is often used for testing hypertext links for
	 * validity, accessibility, and recent modification.
	 * 
	 * @var string
	 */
	const METHOD_HEAD = 'HEAD';
	
	/**
	 * The GET method means retrieve whatever information (in the form of an entity) is identified
	 * by the Request-URI. If the Request-URI refers to a data-producing process, it is the produced
	 * data which shall be returned as the entity in the response and not the source text of the
	 * process, unless that text happens to be the output of the process.
	 * 
	 * @var string
	 */
	const METHOD_GET = 'GET';
	
	/**
	 * The POST method is used to request that the origin server accept the entity enclosed in the
	 * request as a new subordinate of the resource identified by the Request-URI in the Request-Line.
	 * 
	 * @var string
	 */
	const METHOD_POST = 'POST';
	
	/**
	 * The PUT method requests that the enclosed entity be stored under the supplied Request-URI.
	 * If the Request-URI refers to an already existing resource, the enclosed entity SHOULD be
	 * considered as a modified version of the one residing on the origin server. If the Request-URI
	 * does not point to an existing resource, and that URI is capable of being defined as a new
	 * resource by the requesting user agent, the origin server can create the resource with that
	 * URI. If a new resource is created, the origin server MUST inform the user agent via the 201
	 * (Created) response. If an existing resource is modified, either the 200 (OK) or 204 (No Content)
	 * response codes SHOULD be sent to indicate successful completion of the request. If the resource
	 * could not be created or modified with the Request-URI, an appropriate error response SHOULD be
	 * given that reflects the nature of the problem. The recipient of the entity MUST NOT ignore any
	 * Content-* (e.g. Content-Range) headers that it does not understand or implement and MUST return
	 * a 501 (Not Implemented) response in such cases.
	 * 
	 * @var string
	 */
	const METHOD_PUT = 'PUT';
	
	/**
	 * The DELETE method requests that the origin server delete the resource identified by the
	 * Request-URI. This method MAY be overridden by human intervention (or other means) on the
	 * origin server. The client cannot be guaranteed that the operation has been carried out, even
	 * if the status code returned from the origin server indicates that the action has been completed
	 * successfully. However, the server SHOULD NOT indicate success unless, at the time the response
	 * is given, it intends to delete the resource or move it to an inaccessible location.
	 * 
	 * @var string
	 */
	const METHOD_DELETE = 'DELETE';
	
	/**
	 * The TRACE method is used to invoke a remote, application-layer loop- back of the request
	 * message. The final recipient of the request SHOULD reflect the message received back to the
	 * client as the entity-body of a 200 (OK) response. The final recipient is either the origin
	 * server or the first proxy or gateway to receive a Max-Forwards value of zero (0) in the request
	 * (see section 14.31). A TRACE request MUST NOT include an entity.
	 * 
	 * @var string
	 */
	const METHOD_TRACE = 'TRACE';
	
	/**
	 * The OPTIONS method represents a request for information about the communication options
	 * available on the request/response chain identified by the Request-URI. This method allows
	 * the client to determine the options and/or requirements associated with a resource, or the
	 * capabilities of a server, without implying a resource action or initiating a resource retrieval.
	 * 
	 * @var string
	 */
	const METHOD_OPTIONS = 'OPTIONS';
	
	/**
	 * This specification reserves the method name CONNECT for use with a proxy that can dynamically
	 * switch to being a tunnel (e.g. SSL tunneling).
	 * 
	 * @var string
	 */
	const METHOD_CONNECT = 'CONNECT';
	
	/**
	 * The PATCH method requests that a set of changes described in the request entity be applied to
	 * the resource identified by the Request-URI.  The set of changes is represented in a format called
	 * a "patch document" identified by a media type.  If the Request-URI does not point to an existing
	 * resource, the server MAY create a new resource, depending on the patch document type (whether it
	 * can logically modify a null resource) and permissions, etc.
	 * 
	 * @var string
	 */
	const METHOD_PATCH = 'PATCH';
	
	/**
	 * The client SHOULD continue with its request. This interim response is used to
	 * inform the client that the initial part of the request has been received and
	 * has not yet been rejected by the server. The client SHOULD continue by sending
	 * the remainder of the request or, if the request has already been completed, ignore
	 * this response. The server MUST send a final response after the request has been
	 * completed. See section 8.2.3 for detailed discussion of the use and handling
	 * of this status code.
	 * 
	 * @var int
	 */
	const CODE_CONTINUE = 100;
	
	/**
	 * The server understands and is willing to comply with the client's request, via
	 * the Upgrade message header field (section 14.42), for a change in the application
	 * protocol being used on this connection. The server will switch protocols to those
	 * defined by the response's Upgrade header field immediately after the empty line
	 * which terminates the 101 response.
	 * 
	 * @var int
	 */
	const CODE_SWITCHING_PROTOCOLS = 101;
	
	/**
	 * The request has succeeded. The information returned with the response is dependent
	 * on the method used in the request.
	 * 
	 * @var int
	 */
	const CODE_OK = 200;
	
	/**
	 * The request has been fulfilled and resulted in a new resource being created. The newly
	 * created resource can be referenced by the URI(s) returned in the entity of the response, with
	 * the most specific URI for the resource given by a Location header field. The response
	 * SHOULD include an entity containing a list of resource characteristics and location(s) from
	 * which the user or user agent can choose the one most appropriate. The entity format is
	 * specified by the media type given in the Content-Type header field. The origin server
	 * MUST create the resource before returning the 201 status code. If the action cannot be
	 * carried out immediately, the server SHOULD respond with 202 (Accepted) response instead.
	 * 
	 * @var int
	 */
	const CODE_CREATED = 201;
	
	/**
	 * The request has been accepted for processing, but the processing has not been completed.
	 * The request might or might not eventually be acted upon, as it might be disallowed when
	 * processing actually takes place. There is no facility for re-sending a status code from an
	 * asynchronous operation such as this.
	 * 
	 * @var int
	 */
	const CODE_ACCEPTED = 202;
	
	/**
	 * The returned metainformation in the entity-header is not the definitive set as available
	 * from the origin server, but is gathered from a local or a third-party copy. The set presented
	 * MAY be a subset or superset of the original version. For example, including local annotation
	 * information about the resource might result in a superset of the metainformation known by the
	 * origin server. Use of this response code is not required and is only appropriate when the
	 * response would otherwise be 200 (OK).
	 * 
	 * @var int
	 */
	const CODE_NON_AUTHORITATIVE_INFORMATION = 203;
	
	/**
	 * The server has fulfilled the request but does not need to return an entity-body, and might
	 * want to return updated metainformation. The response MAY include new or updated metainformation
	 * in the form of entity-headers, which if present SHOULD be associated with the requested variant.
	 * 
	 * @var int
	 */
	const CODE_NO_CONTENT = 204;
	
	/**
	 * The server has fulfilled the request and the user agent SHOULD reset the document view which
	 * caused the request to be sent. This response is primarily intended to allow input for actions
	 * to take place via user input, followed by a clearing of the form in which the input is given so
	 * that the user can easily initiate another input action. The response MUST NOT include an entity.
	 * 
	 * @var int
	 */
	const CODE_RESET_CONTENT = 205;
	
	/**
	 * The server has fulfilled the partial GET request for the resource. The request MUST have
	 * included a Range header field (section 14.35) indicating the desired range, and MAY have
	 * included an If-Range header field (section 14.27) to make the request conditional.
	 * 
	 * @var int
	 */
	const CODE_PARTIAL_CONTENT = 206;
	
	/**
	 * The requested resource corresponds to any one of a set of representations, each with its own
	 * specific location, and agent- driven negotiation information (section 12) is being provided so
	 * that the user (or user agent) can select a preferred representation and redirect its request to
	 * that location.
	 * 
	 * @var int
	 */
	const CODE_MULTIPLE_CHOICES = 300;
	
	/**
	 * The requested resource has been assigned a new permanent URI and any future references to
	 * this resource SHOULD use one of the returned URIs. Clients with link editing capabilities
	 * ought to automatically re-link references to the Request-URI to one or more of the new
	 * references returned by the server, where possible. This response is cacheable unless
	 * indicated otherwise.
	 * 
	 * @var int
	 */
	const CODE_MOVED_PERMANENTLY = 301;
	
	/**
	 * The requested resource resides temporarily under a different URI. Since the redirection
	 * might be altered on occasion, the client SHOULD continue to use the Request-URI for future
	 * requests. This response is only cacheable if indicated by a Cache-Control or Expires header field.
	 * 
	 * @var int
	 */
	const CODE_FOUND = 302;
	
	/**
	 * The response to the request can be found under a different URI and SHOULD be retrieved using
	 * a GET method on that resource. This method exists primarily to allow the output of a
	 * POST-activated script to redirect the user agent to a selected resource. The new URI is not
	 * a substitute reference for the originally requested resource. The 303 response MUST NOT be
	 * cached, but the response to the second (redirected) request might be cacheable.
	 * 
	 * @var int
	 */
	const CODE_SEE_OTHER = 303;
	
	/**
	 * If the client has performed a conditional GET request and access is allowed, but the document
	 * has not been modified, the server SHOULD respond with this status code. The 304 response MUST
	 * NOT contain a message-body, and thus is always terminated by the first empty line after the
	 * header fields.
	 * 
	 * @var int
	 */
	const CODE_NOT_MODIFIED = 304;
	
	/**
	 * The requested resource MUST be accessed through the proxy given by the Location field.
	 * The Location field gives the URI of the proxy. The recipient is expected to repeat this
	 * single request via the proxy. 305 responses MUST only be generated by origin servers.
	 * 
	 * @var int
	 */
	const CODE_USE_PROXY = 305;
	
	/**
	 * The requested resource resides temporarily under a different URI. Since the redirection
	 * MAY be altered on occasion, the client SHOULD continue to use the Request-URI for future
	 * requests. This response is only cacheable if indicated by a Cache-Control or Expires
	 * header field.
	 * 
	 * @var int
	 */
	const CODE_TEMPORARY_REDIRECT = 307;
	
	/**
	 * The request could not be understood by the server due to malformed syntax. The client
	 * SHOULD NOT repeat the request without modifications. 
	 * 
	 * @var int
	 */
	const CODE_BAD_REQUEST = 400;
	
	/**
	 * The request requires user authentication. The response MUST include a WWW-Authenticate
	 * header field (section 14.47) containing a challenge applicable to the requested resource.
	 * The client MAY repeat the request with a suitable Authorization header field (section 14.8).
	 * If the request already included Authorization credentials, then the 401 response indicates
	 * that authorization has been refused for those credentials. If the 401 response contains the
	 * same challenge as the prior response, and the user agent has already attempted authentication
	 * at least once, then the user SHOULD be presented the entity that was given in the
	 * response, since that entity might include relevant diagnostic information. HTTP access
	 * authentication is explained in "HTTP Authentication: Basic and Digest Access Authentication".
	 * 
	 * @var int
	 */
	const CODE_UNAUTHORIZED = 401;
	
	/**
	 * This code is reserved for future use.
	 * 
	 * @var int
	 */
	const CODE_PAYMENT_REQUIRED = 402;
	
	/**
	 * The server understood the request, but is refusing to fulfill it. Authorization will not
	 * help and the request SHOULD NOT be repeated. If the request method was not HEAD and the
	 * server wishes to make public why the request has not been fulfilled, it SHOULD describe the
	 * reason for the refusal in the entity. If the server does not wish to make this information
	 * available to the client, the status code 404 (Not Found) can be used instead.
	 * 
	 * @var int
	 */
	const CODE_FORBIDDEN = 403;
	
	/**
	 * The server has not found anything matching the Request-URI. No indication is given of
	 * whether the condition is temporary or permanent. The 410 (Gone) status code SHOULD be used
	 * if the server knows, through some internally configurable mechanism, that an old resource
	 * is permanently unavailable and has no forwarding address. This status code is commonly used
	 * when the server does not wish to reveal exactly why the request has been refused, or when
	 * no other response is applicable.
	 * 
	 * @var int
	 */
	const CODE_NOT_FOUND = 404;
	
	/**
	 * The method specified in the Request-Line is not allowed for the resource identified by the
	 * Request-URI. The response MUST include an Allow header containing a list of valid methods
	 * for the requested resource.
	 * 
	 * @var int
	 */
	const CODE_METHOD_NOT_ALLOWED = 405;
	
	/**
	 * The resource identified by the request is only capable of generating response entities which
	 * have content characteristics not acceptable according to the accept headers sent in the request.
	 * 
	 * @var int
	 */
	const CODE_NOT_ACCEPTABLE = 406;
	
	/**
	 * This code is similar to 401 (Unauthorized), but indicates that the client must first
	 * authenticate itself with the proxy. The proxy MUST return a Proxy-Authenticate header
	 * field (section 14.33) containing a challenge applicable to the proxy for the requested
	 * resource. The client MAY repeat the request with a suitable Proxy-Authorization header
	 * field (section 14.34). HTTP access authentication is explained in "HTTP Authentication:
	 * Basic and Digest Access Authentication".
	 * 
	 * @var int
	 */
	const CODE_PROXY_AUTHENTICATION_REQUIRED = 407;
	
	/**
	 * The client did not produce a request within the time that the server was prepared to
	 * wait. The client MAY repeat the request without modifications at any later time.
	 * 
	 * @var int
	 */
	const CODE_REQUEST_TIMEOUT = 408;
	
	/**
	 * The request could not be completed due to a conflict with the current state of the resource.
	 * This code is only allowed in situations where it is expected that the user might be able to
	 * resolve the conflict and resubmit the request. The response body SHOULD include enough
	 * information for the user to recognize the source of the conflict. Ideally, the response
	 * entity would include enough information for the user or user agent to fix the problem;
	 * however, that might not be possible and is not required.
	 * 
	 * @var int
	 */
	const CODE_CONFLICT = 409;
	
	/**
	 * The requested resource is no longer available at the server and no forwarding address is
	 * known. This condition is expected to be considered permanent. Clients with link editing
	 * capabilities SHOULD delete references to the Request-URI after user approval. If the server
	 * does not know, or has no facility to determine, whether or not the condition is
	 * permanent, the status code 404 (Not Found) SHOULD be used instead. This response is
	 * cacheable unless indicated otherwise.
	 * 
	 * @var int
	 */
	const CODE_GONE = 410;
	
	/**
	 * The server refuses to accept the request without a defined Content-Length. The client
	 * MAY repeat the request if it adds a valid Content-Length header field containing the length
	 * of the message-body in the request message.
	 * 
	 * @var int
	 */
	const CODE_LENGTH_REQUIRED = 411;
	
	/**
	 * The precondition given in one or more of the request-header fields evaluated to false when
	 * it was tested on the server. This response code allows the client to place preconditions on
	 * the current resource metainformation (header field data) and thus prevent the requested method
	 * from being applied to a resource other than the one intended.
	 * 
	 * @var int
	 */
	const CODE_PRECONDITION_FAILED = 412;
	
	/**
	 * The server is refusing to process a request because the request entity is larger than the
	 * server is willing or able to process. The server MAY close the connection to prevent the
	 * client from continuing the request.
	 * 
	 * @var int
	 */
	const CODE_REQUEST_ENTITY_TOO_LARGE = 413;
	
	/**
	 * The server is refusing to service the request because the Request-URI is longer than the
	 * server is willing to interpret. This rare condition is only likely to occur when a client
	 * has improperly converted a POST request to a GET request with long query information, when
	 * the client has descended into a URI "black hole" of redirection (e.g., a redirected URI prefix
	 * that points to a suffix of itself), or when the server is under attack by a client attempting
	 * to exploit security holes present in some servers using fixed-length buffers for reading or
	 * manipulating the Request-URI.
	 * 
	 * @var int
	 */
	const CODE_REQUEST_URI_TOO_LONG = 414;
	
	/**
	 * The server is refusing to service the request because the entity of the request is in a format
	 * not supported by the requested resource for the requested method.
	 * 
	 * @var int
	 */
	const CODE_UNSUPPORTED_MEDIA_TYPE = 415;
	
	/**
	 * A server SHOULD return a response with this status code if a request included a Range
	 * request-header field (section 14.35), and none of the range-specifier values in this field
	 * overlap the current extent of the selected resource, and the request did not include an
	 * If-Range request-header field. (For byte-ranges, this means that the first- byte-pos of all
	 * of the byte-range-spec values were greater than the current length of the selected resource.)
	 * 
	 * @var int
	 */
	const CODE_REQUEST_RANGE_NOT_SATISFIABLE = 416;
	
	/**
	 * The expectation given in an Expect request-header field (see section 14.20) could not be met
	 * by this server, or, if the server is a proxy, the server has unambiguous evidence that the
	 * request could not be met by the next-hop server.
	 * 
	 * @var int
	 */
	const CODE_EXPECTATION_FAILED = 417;
	
	/**
	 * The 428 status code indicates that the origin server requires the request to be conditional. Its
	 * typical use is to avoid the "lost update" problem, where a client GETs a resource's state, modifies
	 * it, and PUTs it back to the server, when meanwhile a third party has modified the state on the
	 * server, leading to a conflict. By requiring requests to be conditional, the server can assure that
	 * clients are working with the correct copies.
	 * 
	 * @link http://tools.ietf.org/html/rfc6585
	 * 
	 * @var int
	 */
	const CODE_PRECONDITION_REQUIRED = 428;
	
	/**
	 * The 429 status code indicates that the user has sent too many requests in a given amount of
	 * time ("rate limiting"). The response representations SHOULD include details explaining the
	 * condition, and MAY include a Retry-After header indicating how long to wait before
	 * making a new request.
	 * 
	 * @link http://tools.ietf.org/html/rfc6585
	 * 
	 * @var int
	 */
	const CODE_TOO_MANY_REQUESTS = 429;
	
	/**
	 * The 431 status code indicates that the server is unwilling to process the request because its
	 * header fields are too large. The request MAY be resubmitted after reducing the size of the request
	 * header fields. It can be used both when the set of request header fields in total is too large, and
	 * when a single header field is at fault. In the latter case, the response representation SHOULD
	 * specify which header field was too large.
	 * 
	 * @link http://tools.ietf.org/html/rfc6585
	 * 
	 * @var int
	 */
	const CODE_REQUEST_HEADER_FIELDS_TOO_LARGE = 431;
	
	/**
	 * The server encountered an unexpected condition which prevented it from fulfilling the request.
	 * 
	 * @var int
	 */
	const CODE_INTERNAL_SERVER_ERROR = 500;
	
	/**
	 * The server does not support the functionality required to fulfill the request. This is the
	 * appropriate response when the server does not recognize the request method and is not capable
	 * of supporting it for any resource.
	 * 
	 * @var int
	 */
	const CODE_NOT_IMPLEMENTED = 501;
	
	/**
	 * The server, while acting as a gateway or proxy, received an invalid response from the
	 * upstream server it accessed in attempting to fulfill the request.
	 * 
	 * @var int
	 */
	const CODE_BAD_GATEWAY = 502;
	
	/**
	 * The server is currently unable to handle the request due to a temporary overloading or
	 * maintenance of the server. The implication is that this is a temporary condition which
	 * will be alleviated after some delay. If known, the length of the delay MAY be indicated in
	 * a Retry-After header. If no Retry-After is given, the client SHOULD handle the response as
	 * it would for a 500 response.
	 * 
	 * @var int
	 */
	const CODE_SERVICE_UNAVAILABLE = 503;
	
	/**
	 * The server, while acting as a gateway or proxy, did not receive a timely response from the
	 * upstream server specified by the URI (e.g. HTTP, FTP, LDAP) or some other auxiliary
	 * server (e.g. DNS) it needed to access in attempting to complete the request.
	 * 
	 * @var int
	 */
	const CODE_GATEWAY_TIMEOUT = 504;
	
	/**
	 * The server does not support, or refuses to support, the HTTP protocol version that was
	 * used in the request message. The server is indicating that it is unable or unwilling to
	 * complete the request using the same major version as the client, as described in
	 * section 3.1, other than with this error message. The response SHOULD contain an entity
	 * describing why that version is not supported and what other protocols are supported by
	 * that server.
	 * 
	 * @var int
	 */
	const CODE_HTTP_VERSION_NOT_SUPPORTED = 505;
	
	/**
	 * The 506 status code indicates that the server has an internal configuration error: the
	 * chosen variant resource is configured to engage in transparent content negotiation
	 * itself, and is therefore not a proper end point in the negotiation process.
	 * 
	 * @link http://tools.ietf.org/html/rfc2295
	 * 
	 * @var int
	 */
	const CODE_VARIANT_ALSO_NEGOTIATES = 506;
	
	/**
	 * This status code, while used by many servers, is not specified in any RFCs.
	 * 
	 * @var int
	 */
	const CODE_BANDWIDTH_LIMIT_EXCEEDED = 509;
	
	/**
	 * The policy for accessing the resource has not been met in the request. The server should
	 * send back all the information necessary for the client to issue an extended request. It
	 * is outside the scope of this specification to specify how the extensions inform the client.
	 * 
	 * @link http://tools.ietf.org/html/rfc2774
	 * 
	 * @var int
	 */
	const CODE_NOT_EXTENDED = 510;
	
	/**
	 * The 511 status code indicates that the client needs to authenticate to gain network access. The
	 * response representation SHOULD contain a link to a resource that allows the user to submit
	 * credentials (e.g., with an HTML form). Note that the 511 response SHOULD NOT contain a challenge
	 * or the login interface itself, because browsers would show the login interface as being associated
	 * with the originally requested URL, which may cause confusion.
	 * 
	 * @link http://tools.ietf.org/html/rfc6585
	 * 
	 * @var int
	 */
	const CODE_NETWORK_AUTHENTICATION_REQUIRED = 511;
	
	/**
	 * Perform a permanent redirect to the new location (a GET request will be used to aquire)
	 * the new resource and search engine inedexes and caches will be updated to use the new
	 * resource location.
	 * 
	 * @var int
	 */
	const REDIRECT_PERMANENT = 301;
	
	/**
	 * Perform a GET request to the resource location (discard any POST data and headers
	 * in the process).
	 * 
	 * @var int
	 */
	const REDIRECT_TEMPORARY = 303;
	
	/**
	 * Perform a temporary redirect to the new resource location preserving the request
	 * method, headers and body.
	 * 
	 * @var int
	 */
	const REDIRECT_IDENTICAL = 307;

	/**
	 * Array of response codes and their status messages.
	 * 
	 * @var array<int, string>
	 */
	protected static $reason = [
		self::CODE_CONTINUE							=> 'Continue',
		self::CODE_SWITCHING_PROTOCOLS				=> 'Switching Protocols',
		self::CODE_OK								=> 'OK',
		self::CODE_CREATED							=> 'Created',
		self::CODE_ACCEPTED							=> 'Accepted',
		self::CODE_NON_AUTHORITATIVE_INFORMATION	=> 'Non-Authoritative Information',
		self::CODE_NO_CONTENT						=> 'No Content',
		self::CODE_RESET_CONTENT					=> 'Reset Content',
		self::CODE_PARTIAL_CONTENT					=> 'Partial Content',
		self::CODE_MULTIPLE_CHOICES					=> 'Multiple Choices',
		self::CODE_MOVED_PERMANENTLY				=> 'Moved Permanently',
		self::CODE_FOUND							=> 'Found',
		self::CODE_SEE_OTHER						=> 'See Other',
		self::CODE_NOT_MODIFIED						=> 'Not Modified',
		self::CODE_USE_PROXY						=> 'Use Proxy',
		self::CODE_TEMPORARY_REDIRECT				=> 'Temporary Redirect',
		self::CODE_BAD_REQUEST						=> 'Bad Request',
		self::CODE_UNAUTHORIZED						=> 'Unauthorized',
		self::CODE_PAYMENT_REQUIRED					=> 'Payment Required',
		self::CODE_FORBIDDEN						=> 'Forbidden',
		self::CODE_NOT_FOUND						=> 'Not Found',
		self::CODE_METHOD_NOT_ALLOWED				=> 'Method Not Allowed',
		self::CODE_NOT_ACCEPTABLE					=> 'Not Acceptable',
		self::CODE_PROXY_AUTHENTICATION_REQUIRED	=> 'Proxy Authentication Required',
		self::CODE_REQUEST_TIMEOUT					=> 'Request Timeout',
		self::CODE_CONFLICT							=> 'Conflict',
		self::CODE_GONE								=> 'Gone',
		self::CODE_LENGTH_REQUIRED					=> 'Length Required',
		self::CODE_PRECONDITION_FAILED				=> 'Precondition Failed',
		self::CODE_REQUEST_ENTITY_TOO_LARGE			=> 'Request Entity Too Large',
		self::CODE_REQUEST_URI_TOO_LONG				=> 'Request-URI Too Long',
		self::CODE_UNSUPPORTED_MEDIA_TYPE			=> 'Unsupported Media Type',
		self::CODE_REQUEST_RANGE_NOT_SATISFIABLE	=> 'Requested Range Not Satisfiable',
		self::CODE_EXPECTATION_FAILED				=> 'Expectation Failed',
		self::CODE_PRECONDITION_REQUIRED			=> 'Precondition Required',
		self::CODE_TOO_MANY_REQUESTS				=> 'Too Many Requests',
		self::CODE_REQUEST_HEADER_FIELDS_TOO_LARGE	=> 'Request Header Fields Too Large',
		self::CODE_INTERNAL_SERVER_ERROR			=> 'Internal Server Error',
		self::CODE_NOT_IMPLEMENTED					=> 'Not Implemented',
		self::CODE_BAD_GATEWAY						=> 'Bad Gateway',
		self::CODE_SERVICE_UNAVAILABLE				=> 'Service Unavailable',
		self::CODE_GATEWAY_TIMEOUT					=> 'Gateway Timeout',
		self::CODE_HTTP_VERSION_NOT_SUPPORTED		=> 'HTTP Version Not Supported',
		self::CODE_BANDWIDTH_LIMIT_EXCEEDED			=> 'Bandwidth Limit Exceeded',
		self::CODE_VARIANT_ALSO_NEGOTIATES			=> 'Variant Also Negotiates',
		self::CODE_NOT_EXTENDED						=> 'Not Extended',
		self::CODE_NETWORK_AUTHENTICATION_REQUIRED	=> 'Network Authentication Required'
	];
	
	/**
	 * Check if the given string is a valid HTTP protocol.
	 * 
	 * @param string $protocol
	 * @return bool
	 */
	public static function isProtocol(string $protocol): bool
	{
		switch(strtoupper($protocol))
		{
			case 'HTTP/1.0':
			case 'HTTP/1.1':
				return true;
		}
		
		return false;
	}
	
	/**
	 * Check if the HTTP code is a success code (1## or 2##)
	 * 
	 * @param int $code
	 * @return bool
	 */
	public static function isSuccess(int $code): bool
	{
		switch(floor(intval($code) / 100))
		{
			case 1:
			case 2:
				return true;
		}
		
		return false;
	}
	
	/**
	 * Check if the HTTP code is a redirect code (3##)
	 * 
	 * @param int $code
	 * @return bool
	 */
	public static function isRedirect(int $code): bool
	{
		return (floor(intval($code) / 100) == 3);
	}
	
	/**
	 * Check if the HTTP code is an error code
	 * 
	 * @param int $code
	 * @return bool
	 */
	public static function isError(int $code): bool
	{
		switch(floor(intval($code) / 100))
		{
			case 4:
			case 5:
				return true;
		}
		
		return false;
	}
	
	/**
	 * Arrange an HTTP response status line using the given data.
	 * 
	 * @param int $code HTTP response status code.
	 * @param string $protocol HTTP protocol being used.
	 * @return string Generated status line.
	 */
	public static function getStatusLine(int $code, string $protocol = 'HTTP/1.1'): string
	{
		return $protocol . ' ' . $code . rtrim(' ' . static::getReason($code, ''));
	}
	
	/**
	 * Get the reason message for an HTTP code.
	 * 
	 * @param int $code
	 * @param mixed $default
	 * @return mixed
	 */
	public static function getReason(int $code, string $default = NULL): string
	{
		$code = (int)$code;
		
		if(isset(static::$reason[$code]))
		{
			return static::$reason[$code];
		}
		
		return $default;
	}
	
	/**
	 * Normalize an HTTP header name by capitalizing the first letter and all letters
	 * preceeded by a dash.
	 * 
	 * @param string $name
	 * @return string
	 */
	public static function normalizeHeaderName(string $name): string
	{
		return preg_replace_callback("'-[a-z]'", function($m) {
			return strtoupper($m[0]);
		}, ucfirst(strtolower(trim($name))));
	}
}
