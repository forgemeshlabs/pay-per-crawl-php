<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl;

use ForgeMesh\PayPerCrawl\Contracts\HttpClientInterface;

/** cURL when available, PHP streams otherwise. No other dependencies. */
final class NativeHttpClient implements HttpClientInterface
{
    public function __construct(private string $userAgent = 'forgemesh-pay-per-crawl')
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 45): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $out = curl_exec($ch);
            if ($out === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new \RuntimeException($err !== '' ? $err : 'curl failed');
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['status' => $status, 'body' => (string) $out];
        }

        $ctx = stream_context_create(['http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", array_merge($lines, ['User-Agent: ' . $this->userAgent])),
            'content' => $body ?? '',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'max_redirects' => 2,
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        if ($out === false) {
            throw new \RuntimeException('HTTP request failed');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $status, 'body' => $out];
    }
}
