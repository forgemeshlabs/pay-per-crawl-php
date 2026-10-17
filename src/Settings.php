<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl;

/** Defaults and normalisation shared by every framework adapter. */
final class Settings
{
    public const MIN_PRICE_USD = 0.005;

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'mode' => 'monitor',            // monitor | gate
            'pay_to' => '',
            'price_usd' => 0.01,
            'facilitator_url' => '',        // empty = PayAI (no key, no seller fee)
            'facilitator_auth' => '',
            'class_policy' => [
                'search' => 'free',
                'social' => 'free',
                'monitor' => 'free',
                'seo' => 'paid',
                'ai' => 'paid',
                'automation' => 'paid',
                'bot' => 'paid',
            ],
            'bot_overrides' => [],          // bot name => free|paid|block
            'allow_paths' => [],            // path prefixes that are never gated
            'exempt_paths' => [],           // framework-internal prefixes (admin, login...)
            'log_humans' => true,
            'discovery' => true,
            'publish_contact' => false,
            'trusted_ip_header' => '',      // e.g. CF-Connecting-IP when behind Cloudflare
            'mpp_secret' => '',
            'ip_salt' => '',
            'site_url' => '',               // optional canonical origin; default = request origin
            'site_name' => '',
            'site_description' => '',
            'site_email' => '',
            'llms_pages' => [],             // list of [title, url] shown in /llms.txt
            'pro_key' => '',
            'pro_listed' => true,
        ];
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function normalize(array $in): array
    {
        $d = self::defaults();
        $s = array_merge($d, array_filter($in, static fn ($v) => $v !== null));
        $s['class_policy'] = array_merge($d['class_policy'], (array) $s['class_policy']);
        $s['bot_overrides'] = (array) $s['bot_overrides'];
        $s['allow_paths'] = array_values(array_filter(array_map('strval', (array) $s['allow_paths'])));
        $s['exempt_paths'] = array_values(array_filter(array_map('strval', (array) $s['exempt_paths'])));
        $s['price_usd'] = max(self::MIN_PRICE_USD, (float) $s['price_usd']);
        $s['mode'] = $s['mode'] === 'gate' ? 'gate' : 'monitor';
        $s['trusted_ip_header'] = trim((string) $s['trusted_ip_header']);
        // Never gate without a wallet: a 402 nobody can pay is just a block.
        if ($s['mode'] === 'gate' && !self::validWallet((string) $s['pay_to'])) {
            $s['mode'] = 'monitor';
        }
        return $s;
    }

    public static function validWallet(string $addr): bool
    {
        return (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $addr);
    }
}
