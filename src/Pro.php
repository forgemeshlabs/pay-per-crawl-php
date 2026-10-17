<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl;

use ForgeMesh\PayPerCrawl\Contracts\HitStoreInterface;
use ForgeMesh\PayPerCrawl\Contracts\HttpClientInterface;

/**
 * Optional hosted service (license key). Syncs daily rollups and settlements to the hosted
 * dashboard and pulls the crawler-intel feed. Nothing here runs without a license key; the
 * free package never calls out. State is a plain array the adapter persists between runs.
 */
final class Pro
{
    public const BASE = 'https://proxy.forgemesh.io';

    private const DAY = 86400;

    /** @param array<string,mixed> $settings */
    public function __construct(
        private array $settings,
        private HttpClientInterface $http,
        private string $siteHost,
        private string $siteUrl,
        private string $platform = 'php'
    ) {
    }

    public static function enabled(array $settings): bool
    {
        return !empty($settings['pro_key']) && preg_match('/^fmppc_[A-Za-z0-9_\-]{20,}$/', (string) $settings['pro_key']) === 1;
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->settings['pro_key'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        try {
            $r = $this->http->request($method, self::BASE . $path, $headers, $body === null ? null : (string) json_encode($body), 30);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'code' => 0];
        }
        $json = json_decode($r['body'], true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'HTTP ' . $r['status'], 'code' => $r['status']];
        }
        $json['code'] = $r['status'];
        if ($r['status'] >= 400) {
            $json['ok'] = false;
        }
        return $json;
    }

    /**
     * Verify the license; returns the state patch.
     * @return array<string,mixed>
     */
    public function verify(): array
    {
        $r = $this->request('POST', '/api/wp/license/verify');
        return [
            'verified_at' => time(),
            'ok' => !empty($r['ok']),
            'status' => (string) ($r['status'] ?? ($r['error'] ?? 'error')),
            'email' => (string) ($r['email'] ?? ''),
            'sites_used' => (int) ($r['sites_used'] ?? 0),
            'site_limit' => (int) ($r['site_limit'] ?? 0),
            'dashboard' => (string) ($r['dashboard_url'] ?? self::BASE . '/wp/dashboard'),
        ];
    }

    /**
     * Run one sync pass. Returns the new state (merge of the old one and this run).
     *
     * @param array<string,mixed> $state previous state
     * @return array<string,mixed>
     */
    public function sync(HitStoreInterface $store, array $state, string $siteName): array
    {
        if (!self::enabled($this->settings)) {
            return $state;
        }
        if (empty($state['verified_at']) || time() - (int) $state['verified_at'] > self::DAY) {
            $state = array_merge($state, $this->verify());
        }
        if (empty($state['ok'])) {
            return $state;
        }
        $daily = [];
        foreach ([gmdate('Y-m-d', time() - self::DAY), gmdate('Y-m-d')] as $day) {
            $daily[] = ['day' => $day, 'rows' => $store->dailyRollup($day)];
        }
        $lastId = (int) ($state['last_settlement_id'] ?? 0);
        $settlements = $store->settlementsAfter($lastId, 1000);
        $payload = [
            'site' => [
                'host' => $this->siteHost,
                'url' => $this->siteUrl,
                'name' => $siteName,
                'plugin_version' => $this->platform . '-' . Gate::VERSION,
                'mode' => $this->settings['mode'],
                'price_usd' => (float) $this->settings['price_usd'],
                'pay_to' => (string) $this->settings['pay_to'],
                'listed' => !empty($this->settings['pro_listed']),
            ],
            'daily' => $daily,
            'settlements' => array_map(static function (array $r): array {
                unset($r['id']);
                $r['amount_units'] = (int) $r['amount_units'];
                return $r;
            }, $settlements),
        ];
        $r = $this->request('POST', '/api/wp/ingest', $payload);
        $state['synced_at'] = time();
        $state['sync_ok'] = !empty($r['ok']);
        $state['sync_error'] = empty($r['ok']) ? (string) ($r['error'] ?? 'HTTP ' . ($r['code'] ?? 0)) : '';
        if (!empty($r['ok']) && $settlements) {
            $state['last_settlement_id'] = (int) end($settlements)['id'];
        }

        if (empty($state['intel_at']) || time() - (int) $state['intel_at'] > self::DAY) {
            $intel = $this->request('GET', '/api/wp/intel');
            if (!empty($intel['agents']) && is_array($intel['agents'])) {
                $clean = self::cleanIntel($intel['agents']);
                $state['intel'] = ['version' => (string) ($intel['version'] ?? ''), 'agents' => $clean];
                $state['intel_at'] = time();
                $state['intel_count'] = count($clean);
            }
        }
        return $state;
    }

    /**
     * Validate a pulled feed: only known classes, bounded sizes.
     * @param array<int,mixed> $agents
     * @return list<array<string,mixed>>
     */
    public static function cleanIntel(array $agents): array
    {
        $clean = [];
        foreach (array_slice($agents, 0, 500) as $a) {
            if (!is_array($a) || empty($a['name']) || empty($a['match']) || !is_array($a['match']) || !in_array($a['class'] ?? '', Classifier::classes(), true)) {
                continue;
            }
            $clean[] = [
                'name' => substr(strip_tags((string) $a['name']), 0, 64),
                'class' => $a['class'],
                'match' => array_values(array_filter(array_map(static fn ($m) => strtolower(substr((string) $m, 0, 64)), array_slice($a['match'], 0, 8)))),
                'verify' => array_values(array_filter(array_map('strval', (array) ($a['verify'] ?? [])))),
            ];
        }
        return $clean;
    }
}
