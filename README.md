# ForgeMesh Pay-per-Crawl: PHP core

**Know who crawls your site. Charge the ones that can pay.**

Pay-per-Crawl shows you every AI crawler, SEO crawler and script that fetches your pages, verifies the real search engines by reverse DNS so SEO is untouched, and lets you answer everything else with a standard HTTP 402 price in USDC on Base, paid to your own wallet. Be clear about what pays today: x402-capable agents do; the big AI labs' crawlers (GPTBot, ClaudeBot, PerplexityBot and friends) do not yet pay anyone per page, and they simply get no content from a charging site. Until they do, this is an honest bot ledger with the price already on the door. You see who takes what, you can block or charge any of it, and when a crawler does pay, it goes straight to you. Nobody in between holds the money.

This is the framework-free PHP core, using [x402](https://x402.org) (v1 and v2) and MPP. Humans and verified search engines read free. Bots and agents get an HTTP 402 until they pay.

This package is the engine behind the framework editions:

| Edition | Package |
|---|---|
| Laravel middleware | [`forgemesh/pay-per-crawl-laravel`](https://github.com/forgemeshlabs/pay-per-crawl-laravel) |
| Drupal 10/11 module | [`forgemesh/pay-per-crawl-drupal`](https://github.com/forgemeshlabs/pay-per-crawl-drupal) |
| WordPress plugin | [`forgemesh-pay-per-crawl`](https://github.com/forgemeshlabs/forgemesh-pay-per-crawl) |

Use the core directly if you run Symfony, Slim, CodeIgniter, plain PHP, or anything else: write a thin adapter (about 60 lines).

## Money flow

- The site owner keeps **100%**. Each payment is an EIP-3009 USDC transfer from the paying agent to the `pay_to` address you configure.
- No custody, no account, no signup, **no private key** on your server. Only your public wallet address is configured.
- The facilitator (PayAI by default, no key and no seller fee on Base mainnet) verifies and submits the transfer. A page is served only when the facilitator returns a non-empty transaction hash.
- ForgeMesh takes no cut. There is an optional paid Pro tier (hosted dashboard, alerts, crawler-intel feed); nothing leaves your site without a Pro license key.

If you use Cloudflare, **AI Crawl Control (and any bot-fight or WAF rule that blocks AI crawlers) must be set to Allow** for the crawlers you want to charge. A crawler blocked at the edge never reaches your server and never sees the 402.

## Install

```bash
composer require forgemesh/pay-per-crawl-core
```

Until the package is listed on Packagist, add the repository first:

```bash
composer config repositories.ppc-core vcs https://github.com/forgemeshlabs/pay-per-crawl-php
composer require forgemesh/pay-per-crawl-core:^0.1
```

Requires PHP 8.1+. No other dependencies (cURL is used when present, PHP streams otherwise).

## Use

```php
use ForgeMesh\PayPerCrawl\{Gate, Request, NativeHttpClient, ArrayCache};

$gate = new Gate(
    settings: ['mode' => 'gate', 'pay_to' => '0xYourBaseWallet', 'price_usd' => 0.01, 'mpp_secret' => 'long-random-string'],
    cache: $yourCache,               // CacheInterface: get/set/add(atomic)/delete  (replay lock + DNS cache)
    http: new NativeHttpClient(),    // HttpClientInterface
    store: $yourHitStore,            // optional HitStoreInterface: one row per classified request
);

$result = $gate->handle(new Request($method, $uri, $absoluteUrl, $headers, $remoteIp));
if ($result->respond) {              // 402 (or 403 for a blocked crawler)
    // send $result->status, $result->headers, $result->body and stop
}
// otherwise render your page and add $result->passHeaders to the response
// (payment receipts: PAYMENT-RESPONSE, X-PAYMENT-RESPONSE, Payment-Receipt)
```

Also included: `Discovery` (`/.well-known/x402.json`, `/llms.txt`, `/agents.txt`, robots.txt lines), `Classifier` (85 known agents, reverse-DNS verification for search engines, impostors demoted), `X402` (v1/v2/MPP wire formats), `Keccak` (pure PHP, for the MPP nonce binding), and `Pro` (optional hosted sync).

### Settings

| Key | Default | Meaning |
|---|---|---|
| `mode` | `monitor` | `monitor` classifies and logs only; `gate` charges. Falls back to `monitor` without a valid `pay_to`. |
| `pay_to` | `''` | Your Base wallet address. |
| `price_usd` | `0.01` | Per page; minimum `0.005`. |
| `facilitator_url` / `facilitator_auth` | PayAI / none | Any x402 facilitator that supports Base `exact`. |
| `class_policy` | search/social/monitor `free`, others `paid` | `free`, `paid` or `block` per class (`search social monitor seo ai automation bot`). |
| `bot_overrides` | `[]` | `['GPTBot' => 'paid', 'Bytespider' => 'block']`. |
| `allow_paths` / `exempt_paths` | `[]` | Path prefixes never gated; matched on a segment boundary (`/pricing` exempts `/pricing/plans`, not `/pricing-secret`). |
| `trusted_ip_header` | `''` | Header holding the real client IP behind a proxy. **It must be a header your proxy overwrites or appends to.** For a multi-value header such as `X-Forwarded-For` the **right-most** entry (added by your proxy) is used; the left-most is client-controlled and ignored. Single-value edge headers (`CF-Connecting-IP`) are taken as-is. Never set it if the origin is reachable directly. |
| `mpp_secret`, `ip_salt` | required / falls back to `mpp_secret` | Secrets for MPP challenge signing and the daily IP hash. |
| `site_url` | request origin | Pin the canonical origin behind a rewriting proxy. |

Exemptions are anchored and normalised: exact files (`/robots.txt`, `/llms.txt`, `/agents.txt`, `/favicon.ico`), `*sitemap*.xml`, and the `/.well-known/` directory. Paths with encoded slashes or dots, dot segments, or doubled slashes are never exempt.

## Test

```bash
php tests/run.php        # 156 checks, no network
```

HTTP matrix against a running app and a mock facilitator: `tests/integration/matrix.sh` (used by the Laravel and Drupal repos).

## Verified on Base mainnet

Real paid calls through the PayAI facilitator against the Laravel edition, $0.01 each to the same wallet, receipts checked on `mainnet.base.org`:

| Rail | Transaction |
|---|---|
| x402 v1 (`X-PAYMENT`) | `0xfc7d0a33ab2baeb50f91109db26d4e882e627f66c0cb68b13363a8f7b09e3b68` |
| x402 v2 (`PAYMENT-SIGNATURE`) | `0xff1dfa134999dc4ad19bd8a9f9ae05e286e5c9e9714bc13c4979a3c073d7b63d` |

## License

MIT. (The Drupal and WordPress editions are GPL-2.0-or-later as those ecosystems require; they depend on this MIT core.)

Built by [ForgeMesh Labs](https://forgemesh.io) - hello@forgemesh.io
