<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl;

/** The few facts about an HTTP request the gate needs. Adapters build one from their framework. */
final class Request
{
    /** @var array<string,string> */
    public array $headers;

    /**
     * @param string $uri     path plus query string, e.g. "/post?id=3"
     * @param string $url     absolute URL as the client addressed it
     * @param array<string,string> $headers any case; lowercased here
     */
    public function __construct(
        public string $method,
        public string $uri,
        public string $url,
        array $headers,
        public string $ip = ''
    ) {
        $this->method = strtoupper($method);
        $this->headers = [];
        foreach ($headers as $k => $v) {
            $this->headers[strtolower((string) $k)] = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        }
    }

    public function path(): string
    {
        $p = parse_url('http://x' . ($this->uri !== '' && $this->uri[0] === '/' ? $this->uri : '/' . $this->uri), PHP_URL_PATH);
        return is_string($p) && $p !== '' ? $p : '/';
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
