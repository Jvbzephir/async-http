# KoolKode Async HTTP

[![Build Status](https://travis-ci.org/koolkode/async-http.svg?branch=master)](https://travis-ci.org/koolkode/async-http)

Async HTTP server and client written in PHP 7.

## Features

| Protocol | Server | Client |
|---|---|---|
|**HTTP/1.0** | :heavy_check_mark: | :heavy_check_mark: |
| `Secure connections (SSL / TLS)` | yes | yes |
| `Persistent connections` | yes | yes (limited number per host) |
| `Body streaming` | yes | yes |
| `Proxy support` | yes (trusted proxies, forwarded headers) | no (planned) |
| **HTTP/1.1** | :heavy_check_mark: | :heavy_check_mark: |
| `Upgrade handling` | yes (before & after dispatch) | yes (can take over socket after response) |
| `Range requests` | | no (can be requested via HTTP header) |
| `Expect & 100-continue` | yes | yes (option to disable) |
| `Compression` | gzip, deflate (middleware) | gzip, deflate (middleware) |
| `Persistent connections` | yes | yes (limited number per host) |
| `Pipelining` | yes | |
| `Chunked encoding` | yes | yes |
| `Trailers` | not used | |
| **HTTP/2.0** | :heavy_check_mark: | :heavy_check_mark: |
| `ALPN` | yes | yes |
| `Upgrade: h2c` | yes | |
| `Direct` | yes | |
| `HPACK compression` | yes | yes |
| `Flow control` | yes | yes |
| `Server push` | yes | no |
| **FCGI** | (:heavy_check_mark:) responder | |
| `Persistent connections` | yes | |
| **WebSocket** | :heavy_check_mark: | :heavy_check_mark: |
| `RFC 6455` | yes | yes |
| `Ping` | yes | yes |
| `Text messages` | yes | yes |
| `Binary messages` | yes (streams) | yes (streams) |
| `App protocol negotiation` | yes | yes |
| `Compression` | permessage-deflate (optional) | permessage-deflate (optional) |
| **SSE** (Server-sent events) | :heavy_check_mark: | |
| `Event types` | yes | |
| `Retry setting` | no (planned) | |
| `Event IDs / last Id header` | no (planned) | |
