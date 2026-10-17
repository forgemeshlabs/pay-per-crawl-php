<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl;

use ForgeMesh\PayPerCrawl\Contracts\CacheInterface;
use ForgeMesh\PayPerCrawl\Contracts\HitStoreInterface;
use ForgeMesh\PayPerCrawl\Contracts\HttpClientInterface;

/**
 * The gate decision engine: classify the request, apply policy, and answer with a
 * GateResult (402 / 403 / pass). No framework calls; adapters turn the result into a response.
 */
final class Gate
{
    public const VERSION = '0.1.0';

    private const NONCE_LOCK_SECONDS = 900;
    private const EXACT_EXEMPT = ['/robots.txt', '/llms.txt', '/agents.txt', '/favicon.ico'];

    /** @var array<string,mixed> */
    private array $s;
    private Classifier $classifier;

    /** @param array<string,mixed> $settings raw or normalised; see Settings::defaults() */
    public function __construct(
        array $settings,
        private CacheInterface $cache,
        private HttpClientInterface $http,
        private ?HitStoreInterface $store = null,
        ?Classifier $classifier = null,
        private string $generator = 'forgemesh-pay-per-crawl-php'
    ) {
        $this->s = Settings::normalize($settings);
        $this->classifier = $classifier ?? new Classifier($cache);
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        return $this->s;
    }

    /**
     * Exemptions are anchored: exact files, a sitemap regex, /.well-known/ as a directory, and
     * user prefixes only on a path-segment boundary. Anything that needs normalising (dot
     * segments, doubled slashes) or carries an encoded slash or dot is never exempt, so
     * "/.well-known/../post" or "/robots.txt.html" cannot buy a free read.
     */
    public function isExempt(Request $req): bool
    {
        $raw = $req->path();
        if (preg_match('/%2[fe]/i', $raw)) {
            return false;
        }
        $path = self::normalizePath($raw);
        if (rtrim($path, '/') !== rtrim($raw, '/')) {
            return false;
        }
        $bare = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
        if (in_array($bare, self::EXACT_EXEMPT, true)) {
            return true;
        }
        if (preg_match('#^/[a-z0-9_-]*sitemap[a-z0-9_-]*\.xml$#', $bare)) {
            return true;
        }
        if (str_starts_with($path, '/.well-known/')) {
            return true;
        }
        foreach (array_merge($this->s['exempt_paths'], $this->s['allow_paths']) as $p) {
            $p = rtrim(trim((string) $p), '/');
            if ($p === '') {
                continue;
            }
            if ($bare === $p || str_starts_with($path, $p . '/')) {
                return true;
            }
        }
        return false;
    }

    /** Resolve "." and ".." segments and doubled slashes the way a web server would. */
    private static function normalizePath(string $path): string
    {
        $out = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $i => $seg) {
            if ($seg === '' && $i !== 0 && $i !== $last) {
                $out[] = '';          // keep doubled slashes visible so they register as a change
                continue;
            }
            if ($seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        $n = implode('/', $out);
        return $n === '' || $n[0] !== '/' ? '/' . ltrim($n, '/') : $n;
    }

    public function handle(Request $req): GateResult
    {
        if ($this->isExempt($req)) {
            return GateResult::pass();
        }
        $url = $this->resourceUrl($req);
        $ip = $this->clientIp($req);
        $h = $req->headers;
        $ua = $h['user-agent'] ?? '';
        $c = $this->classifier->classify($ua, $h, $ip);
        $row = [
            'ua_class' => $c['class'],
            'bot_name' => $c['name'],
            'verified' => $c['verified'],
            'method' => substr($req->method, 0, 8),
            'path' => $req->path(),
            'ip_hash' => $this->ipHash($ip),
            'ua' => $ua,
        ];
        $x402 = $this->x402($req);
        $linkPass = $this->linkPassHeaders($req);

        try {
            $payment = $x402->extract_payment($h, $url);
        } catch (\TypeError) {
            $payment = ['rail' => 'x402v2', 'error' => 'malformed_payment'];
        }

        if ($c['class'] === 'human' && !$payment) {
            if ($this->s['log_humans']) {
                $this->log($row + ['status' => 'passed']);
            }
            return GateResult::pass($linkPass);
        }

        $policy = $this->policyFor($c);

        // A payment on a free class is still honoured (and logged as revenue).
        if ($policy === 'free' && !$payment) {
            $this->log($row + ['status' => 'passed']);
            return GateResult::pass($linkPass);
        }
        if ($policy === 'block') {
            $this->log($row + ['status' => 'blocked']);
            return $this->blocked();
        }
        if (!$payment) {
            if ($this->s['mode'] === 'monitor' || $policy === 'free') {
                $this->log($row + ['status' => 'monitor', 'amount_units' => (int) X402::usd_to_units($this->s['price_usd'])]);
                return GateResult::pass($linkPass);
            }
            $this->log($row + ['status' => 'unpaid']);
            return $this->challenge($x402, $req, $url, null);
        }

        // Payment presented.
        $row['rail'] = $payment['rail'];
        if (!empty($payment['error'])) {
            $this->log($row + ['status' => 'rejected', 'reason' => $payment['error']]);
            return $this->challenge($x402, $req, $url, $payment['error'], $payment['detail'] ?? null, $payment['rail']);
        }
        try {
            $pre = $x402->precheck($payment, $url);
        } catch (\TypeError) {
            $pre = 'malformed_authorization';
        }
        if ($pre) {
            $this->log($row + ['status' => 'rejected', 'reason' => $pre]);
            return $this->challenge($x402, $req, $url, $pre, null, $payment['rail']);
        }
        $auth = X402::authorization_of($payment);
        $lock = 'ppc_nonce_' . md5(strtolower((string) $auth['nonce']));
        if (!$this->cache->add($lock, 1, self::NONCE_LOCK_SECONDS)) {
            $this->log($row + ['status' => 'rejected', 'reason' => 'payment_in_flight']);
            return $this->challenge($x402, $req, $url, 'payment_in_flight', 'This authorization was already presented.', $payment['rail']);
        }

        $r = $x402->verify_and_settle($payment, $url);
        if (empty($r['ok'])) {
            if ($r['reason'] === 'facilitator_unreachable') {
                $this->cache->delete($lock); // let the client retry the same authorization.
            }
            $this->log($row + ['status' => 'settle_failed', 'reason' => $r['reason'], 'payer' => (string) ($r['payer'] ?? $auth['from'] ?? '')]);
            return $this->challenge($x402, $req, $url, $r['reason'], $r['detail'] ?? null, $payment['rail']);
        }

        $this->log($row + [
            'status' => 'paid',
            'amount_units' => (int) $auth['value'],
            'tx_hash' => $r['tx'],
            'payer' => (string) ($r['payer'] ?? $auth['from'] ?? ''),
        ]);
        $settleB64 = base64_encode((string) json_encode($r['settle']));
        $pass = $linkPass + [
            'PAYMENT-RESPONSE' => $settleB64,
            'X-PAYMENT-RESPONSE' => $settleB64,
            'Cache-Control' => 'private, no-store',
        ];
        if ($payment['rail'] === 'mpp') {
            $pass['Payment-Receipt'] = $x402->mpp_receipt($r['tx']);
        }
        return GateResult::pass($pass);
    }

    /** @param array{class:string,name:string} $c */
    private function policyFor(array $c): string
    {
        $over = (array) $this->s['bot_overrides'];
        if ($c['name'] !== '' && isset($over[$c['name']]) && in_array($over[$c['name']], ['free', 'paid', 'block'], true)) {
            return $over[$c['name']];
        }
        $p = ((array) $this->s['class_policy'])[$c['class']] ?? 'paid';
        return in_array($p, ['free', 'paid', 'block'], true) ? $p : 'paid';
    }

    // ── Responses ───────────────────────────────────────────────────────────

    private function challenge(X402 $x402, Request $req, string $url, ?string $reason, ?string $detail = null, ?string $rail = null): GateResult
    {
        $desc = sprintf('%s: one page fetch (HTML)', $this->siteName($req));
        $headers = [
            'Cache-Control' => 'no-store',
            'PAYMENT-REQUIRED' => base64_encode((string) json_encode($x402->envelope_v2($url, $desc, $reason ?: 'Payment required'))),
            'WWW-Authenticate' => $x402->mpp_challenge($desc),
            'Link' => '<' . $this->origin($req) . '/.well-known/x402.json>; rel="describedby"; type="application/json"',
            'X-Robots-Tag' => 'noindex',
        ];
        if ($rail === 'mpp' && $reason) {
            $headers['Content-Type'] = 'application/problem+json; charset=utf-8';
            $body = json_encode(['type' => X402::MPP_PROBLEMS . $reason, 'title' => 'Payment Required', 'status' => 402, 'detail' => $detail ?: $reason], JSON_UNESCAPED_SLASHES);
            return GateResult::respond(402, $headers, (string) $body);
        }
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $body = $req->method === 'HEAD' ? '' : (string) json_encode($x402->body_v1($url, $desc, $reason ? 'Payment rejected' : 'Payment required', $reason), JSON_UNESCAPED_SLASHES);
        return GateResult::respond(402, $headers, $body);
    }

    private function blocked(): GateResult
    {
        return GateResult::respond(403, [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'application/json; charset=utf-8',
        ], (string) json_encode(['error' => 'forbidden', 'detail' => 'This crawler is not permitted on this site.']));
    }

    /** @return array<string,string> */
    private function linkPassHeaders(Request $req): array
    {
        if (empty($this->s['discovery'])) {
            return [];
        }
        $o = $this->origin($req);
        return ['Link' => '<' . $o . '/.well-known/x402.json>; rel="describedby"; type="application/json", <' . $o . '/llms.txt>; rel="llms-txt"; type="text/plain"'];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function x402(Request $req): X402
    {
        return new X402([
            'pay_to' => $this->s['pay_to'],
            'price_usd' => $this->s['price_usd'],
            'facilitator_url' => $this->s['facilitator_url'],
            'facilitator_auth' => $this->s['facilitator_auth'],
            'mpp_secret' => $this->s['mpp_secret'],
            'realm' => $this->realm($req),
            'site_name' => $this->siteName($req),
            'manifest_url' => $this->origin($req) . '/.well-known/x402.json',
            'plugin' => $this->generator,
        ], $this->http);
    }

    private function origin(Request $req): string
    {
        if ($this->s['site_url'] !== '') {
            return rtrim((string) $this->s['site_url'], '/');
        }
        $p = parse_url($req->url);
        $o = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'localhost');
        return isset($p['port']) ? $o . ':' . $p['port'] : $o;
    }

    private function resourceUrl(Request $req): string
    {
        return $this->s['site_url'] !== '' ? $this->origin($req) . $req->uri : $req->url;
    }

    private function realm(Request $req): string
    {
        return (string) (parse_url($this->origin($req), PHP_URL_HOST) ?: 'localhost');
    }

    private function siteName(Request $req): string
    {
        return $this->s['site_name'] !== '' ? (string) $this->s['site_name'] : $this->realm($req);
    }

    private function clientIp(Request $req): string
    {
        $ip = $req->ip;
        if ($this->s['trusted_ip_header'] !== '') {
            // Multi-value headers (X-Forwarded-For): the RIGHT-MOST entry is the one our own proxy
            // appended; everything to its left is client-controlled and could carry a spoofed
            // Googlebot address. Single-value edge headers (CF-Connecting-IP) have one entry.
            $parts = array_map('trim', explode(',', $req->header($this->s['trusted_ip_header'])));
            $last = end($parts);
            if ($last !== false && $last !== '' && filter_var($last, FILTER_VALIDATE_IP)) {
                $ip = $last;
            }
        }
        return $ip;
    }

    /** Daily-salted, truncated IP hash: enough to count distinct sources, never a stored IP. */
    private function ipHash(string $ip): string
    {
        $salt = $this->s['ip_salt'] !== '' ? $this->s['ip_salt'] : $this->s['mpp_secret'];
        return substr(hash('sha256', $salt . gmdate('Y-m-d') . $ip), 0, 16);
    }

    /** @param array<string,mixed> $row */
    private function log(array $row): void
    {
        if ($this->store) {
            $this->store->record($row + ['ts' => gmdate('Y-m-d H:i:s')]);
        }
    }
}
