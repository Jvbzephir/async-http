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

namespace KoolKode\Async\Http;

use KoolKode\Async\Context;
use KoolKode\Async\Promise;
use KoolKode\Async\Http\Body\StreamBody;
use KoolKode\Async\Http\Body\StringBody;
use KoolKode\Async\Stream\ReadableStream;

class RequestBuilder
{
    protected $client;
    
    protected $request;
    
    public function __construct(HttpClient $client, $uri, string $method = Http::GET)
    {
        $this->client = $client;
        $this->request = new HttpRequest($uri, $method);
    }
    
    public function method(string $method): self
    {
        $this->request = $this->request->withMethod($method);
        
        return $this;
    }
    
    public function header(string $name, string ...$values): self
    {
        $this->request = $this->request->withAddedHeader($name, ...$values);
        
        return $this;
    }

    public function body($body): self
    {
        if ($body instanceof HttpBody) {
            $this->request = $this->request->withBody($body);
        } elseif ($body instanceof ReadableStream) {
            $this->request = $this->request->withBody(new StreamBody($body));
        } else {
            $this->request = $this->request->withBody(new StringBody((string) $body));
        }
        
        return $this;
    }
    
    public function text(string $body, string $encoding = 'utf-8'): self
    {
        $this->request = $this->request->withHeader('Content-Type', \sprintf('application/json; charset="%s"', $encoding));
        $this->request = $this->request->withBody(new StringBody($body));
        
        return $this;
    }
    
    public function json($body, int $flags = \JSON_UNESCAPED_SLASHES): self
    {
        $body = new StringBody(\json_encode($body, $flags));
        
        $this->request = $this->request->withHeader('Content-Type', 'application/json');
        $this->request = $this->request->withBody($body);
        
        return $this;
    }
    
    public function form(array $fields): RequestBuilder
    {
        $body = new StringBody(\http_build_query($fields, '', '&', \PHP_QUERY_RFC3986));
        
        $this->request = $this->request->withHeader('Content-Type', Http::FORM_ENCODED);
        $this->request = $this->request->withBody($body);
        
        return $this;
    }
    
    public function loadJson(Context $context): Promise
    {
        return $context->task(function (Context $context) {
            $request = $this->request->withHeader('Accept', 'application/json, */*;q=.5');
            $response = yield $this->client->send($context, $request);
            
            return \json_decode(yield $response->getBody()->getContents($context));
        });
    }

    public function attribute(string $name, $value): self
    {
        $this->request = $this->request->withAttribute($name, $value);
        
        return $this;
    }
    
    public function expectContinue(bool $expect): self
    {
        $settings = $this->request->getAttribute(ClientSettings::class) ?? new ClientSettings();
        $this->request = $this->request->withAttribute(ClientSettings::class, $settings->withExpectContinue($expect));
        
        return $this;
    }
    
    public function build(): HttpRequest
    {
        return $this->request;
    }

    public function send(Context $context): Promise
    {
        return $this->client->send($context, $this->request);
    }
}
