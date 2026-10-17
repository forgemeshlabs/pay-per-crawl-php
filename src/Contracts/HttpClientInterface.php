<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl\Contracts;

interface HttpClientInterface
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     * @throws \RuntimeException on transport failure (DNS, timeout, TLS)
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 45): array;
}
