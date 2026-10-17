<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl;

/**
 * Discovery documents served from the site's own domain so paying agents and x402 indexes
 * find the paid copy: /.well-known/x402.json, /llms.txt, /agents.txt, robots.txt lines.
 */
final class Discovery
{
    /** @var array<string,mixed> */
    private array $s;

    /** @param array<string,mixed> $settings */
    public function __construct(array $settings, private string $origin, private string $generator = 'forgemesh-pay-per-crawl-php')
    {
        $this->s = Settings::normalize($settings);
        $this->origin = rtrim($this->s['site_url'] !== '' ? (string) $this->s['site_url'] : $origin, '/');
    }

    /** @return array{type:string,body:string}|null null when the path is not a discovery document */
    public function resolve(string $path): ?array
    {
        if (empty($this->s['discovery'])) {
            return null;
        }
        switch (rtrim($path, '/')) {
            case '/.well-known/x402.json':
                return ['type' => 'application/json; charset=utf-8', 'body' => (string) json_encode($this->manifest(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)];
            case '/llms.txt':
                return ['type' => 'text/plain; charset=utf-8', 'body' => $this->llms()];
            case '/agents.txt':
                return ['type' => 'text/plain; charset=utf-8', 'body' => $this->agents()];
        }
        return null;
    }

    /** Headers every discovery document is served with. */
    public static function headers(string $type): array
    {
        return [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
        ];
    }

    private function name(): string
    {
        return $this->s['site_name'] !== '' ? (string) $this->s['site_name'] : (string) parse_url($this->origin, PHP_URL_HOST);
    }

    private function paid(): bool
    {
        return $this->s['mode'] === 'gate' && !empty($this->s['pay_to']);
    }

    private function price(): string
    {
        return rtrim(rtrim(number_format((float) $this->s['price_usd'], 6, '.', ''), '0'), '.');
    }

    /** @return array<string,mixed> */
    public function manifest(): array
    {
        $price = (float) $this->s['price_usd'];
        $host = (string) parse_url($this->origin, PHP_URL_HOST);
        $res = [
            'resource' => $this->origin . '/{path}',
            'type' => 'http',
            'metadata' => [
                'method' => 'GET',
                'name' => sprintf('%s: page fetch', $this->name()),
                'description' => sprintf('Any page on %s, served as HTML to automated clients after an x402 payment of $%s USDC on Base. Humans and verified search engines read free.', $host, $this->price()),
                'priceUsd' => $price,
                'mimeType' => 'text/html',
                'paid' => $this->paid(),
            ],
            'accepts' => [[
                'scheme' => 'exact',
                'network' => X402::NETWORK_V2,
                'amount' => X402::usd_to_units($price),
                'asset' => X402::USDC,
                'payTo' => (string) $this->s['pay_to'],
                'maxTimeoutSeconds' => X402::TIMEOUT_SECS,
                'extra' => ['name' => X402::USDC_NAME, 'version' => X402::USDC_VERSION],
            ]],
        ];
        return [
            'x402Version' => 2,
            'provider' => [
                'name' => $this->name(),
                'url' => $this->origin . '/',
                'description' => (string) $this->s['site_description'],
                'contact' => $this->s['site_email'] !== '' && !empty($this->s['publish_contact']) ? (string) $this->s['site_email'] : null,
            ],
            'resources' => [$res],
            'protocols' => ['x402', 'mpp'],
            'generator' => $this->generator . '/' . Gate::VERSION,
            'sitemap' => $this->origin . '/sitemap.xml',
        ];
    }

    public function llms(): string
    {
        $l = [];
        $l[] = '# ' . $this->name();
        $l[] = '';
        $l[] = '> ' . $this->s['site_description'];
        $l[] = '';
        $l[] = 'Site: ' . $this->origin . '/';
        $l[] = 'Sitemap: ' . $this->origin . '/sitemap.xml';
        $l[] = '';
        $l[] = '## Access for automated clients';
        $l[] = '';
        if ($this->paid()) {
            $l[] = sprintf('Pages on this site answer HTTP 402 to crawlers and agents. Pay $%s USDC on Base per page with x402 (PAYMENT-SIGNATURE or X-PAYMENT header) or MPP (Authorization: Payment) and retry the same URL.', $this->price());
        } else {
            $l[] = 'This site is instrumented for x402 pay-per-crawl; charging is not enabled yet. Terms will appear in the manifest below when it is.';
        }
        $l[] = 'Verified search engines and humans are never charged.';
        $l[] = '';
        $l[] = 'Manifest: ' . $this->origin . '/.well-known/x402.json';
        $l[] = 'Agents: ' . $this->origin . '/agents.txt';
        $l[] = 'Protocol: https://x402.org';
        $pages = (array) $this->s['llms_pages'];
        if ($pages) {
            $l[] = '';
            $l[] = '## Pages';
            $l[] = '';
            foreach ($pages as $p) {
                $l[] = '- [' . str_replace(["\n", "\r"], ' ', (string) ($p[0] ?? $p['title'] ?? '')) . '](' . (string) ($p[1] ?? $p['url'] ?? '') . ')';
            }
        }
        return implode("\n", $l) . "\n";
    }

    public function agents(): string
    {
        $host = (string) parse_url($this->origin, PHP_URL_HOST);
        $l = [
            '# agents.txt - ' . $host,
            '# Standard: https://agents-txt.com',
            '',
            'Name: ' . $this->name(),
            'Provider: ' . $this->name() . ' (' . $this->origin . '/)',
            'Protocols: x402, mpp, http',
            'Payments: ' . ($this->paid() ? 'required' : 'none'),
            '',
            'LLMs: ' . $this->origin . '/llms.txt',
            'x402: ' . $this->origin . '/.well-known/x402.json',
            'Sitemap: ' . $this->origin . '/sitemap.xml',
            '',
            '## Payment',
            'Protocol: x402 (HTTP 402 challenge; pay and retry with PAYMENT-SIGNATURE) and MPP (WWW-Authenticate: Payment)',
            'Network: ' . X402::NETWORK_V2,
            'Asset: ' . X402::USDC . ' (USDC)',
            'Price: $' . $this->price() . ' per page',
            'PayTo: ' . (string) $this->s['pay_to'],
            '',
        ];
        return implode("\n", $l);
    }

    /** Lines to append to robots.txt. */
    public function robotsLines(): string
    {
        if (empty($this->s['discovery'])) {
            return '';
        }
        return "\n# x402 pay-per-crawl (forgemesh-pay-per-crawl)\n"
            . '# Manifest: ' . $this->origin . "/.well-known/x402.json\n"
            . '# LLMs: ' . $this->origin . "/llms.txt\n"
            . '# Agents: ' . $this->origin . "/agents.txt\n";
    }
}
