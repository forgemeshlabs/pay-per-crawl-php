<?php

declare(strict_types=1);

// Plain test runner, no network, no dependencies. Run: php tests/run.php

spl_autoload_register(static function (string $c): void {
    $p = 'ForgeMesh\\PayPerCrawl\\';
    if (str_starts_with($c, $p)) {
        $f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
        if (is_file($f)) {
            require $f;
        }
    }
});

use ForgeMesh\PayPerCrawl\ArrayCache;
use ForgeMesh\PayPerCrawl\Classifier;
use ForgeMesh\PayPerCrawl\Contracts\HitStoreInterface;
use ForgeMesh\PayPerCrawl\Contracts\HttpClientInterface;
use ForgeMesh\PayPerCrawl\Discovery;
use ForgeMesh\PayPerCrawl\Gate;
use ForgeMesh\PayPerCrawl\Keccak;
use ForgeMesh\PayPerCrawl\Pro;
use ForgeMesh\PayPerCrawl\Request;
use ForgeMesh\PayPerCrawl\Settings;
use ForgeMesh\PayPerCrawl\X402;

$pass = 0;
$fail = 0;
function ok(bool $cond, string $name): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $name\n";
    }
}

final class FakeHttp implements HttpClientInterface
{
    /** @var list<array{string,string,array}> */
    public array $calls = [];
    /** @var callable */
    public $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 45): array
    {
        $b = $body === null ? [] : (json_decode($body, true) ?? []);
        $this->calls[] = [$method, $url, $b, $headers];
        return ($this->handler)($method, $url, $b);
    }
}

final class MemStore implements HitStoreInterface
{
    public array $rows = [];

    public function record(array $row): int
    {
        $row['id'] = count($this->rows) + 1;
        $this->rows[] = $row;
        return $row['id'];
    }

    public function dailyRollup(string $day): array
    {
        return [['bot_name' => 'GPTBot', 'ua_class' => 'ai', 'hits' => 3, 'paid' => 1, 'unpaid' => 2, 'rejected' => 0, 'monitor' => 0, 'revenue_units' => 10000, 'would_units' => 0, 'sources' => 1]];
    }

    public function settlementsAfter(int $afterId, int $limit): array
    {
        return array_values(array_filter($this->rows, static fn ($r) => $r['status'] === 'paid' && $r['id'] > $afterId));
    }

    public function last(): array
    {
        return $this->rows[count($this->rows) - 1];
    }
}

$WALLET = '0x13C3C28c64cc9d60eEb8a675F3fe27eBC6a4Cd09';
$UA_H = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
$UA_G = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)';

// ── Keccak-256 (vectors from viem keccak256) ────────────────────────────────
ok(Keccak::hex('') === '0xc5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470', 'keccak empty');
ok(Keccak::hex('abc') === '0x4e03657aea45a94fc7d47ba826c8d667c0d1e6e33a64a036ec44f58fa12d6c45', 'keccak abc');
ok(Keccak::hex(str_repeat('a', 135)) === '0x34367dc248bbd832f4e3e69dfaac2f92638bd0bbd18f2912ba4ef454919cf446', 'keccak 135 (pad edge)');
ok(Keccak::hex(str_repeat('a', 136)) === '0xa6c4d403279fe3e0af03729caada8374b5ca54d8065329a3ebcaeb4b60aa386e', 'keccak 136 (full block)');
ok(Keccak::hex(str_repeat('a', 137)) === '0xd869f639c7046b4929fc92a4d988a8b22c55fbadb802c0c66ebcd484f1915f39', 'keccak 137');
ok(Keccak::hex(str_repeat('xyz', 100)) === '0xcc0b928a8fb33dca301e35d6070e6cccfda8a90b0ce76912018dd71168c42ad2', 'keccak multi-block');

// ── Classifier ──────────────────────────────────────────────────────────────
$fakeRdns = static function (string $ip, array $suffixes): ?bool {
    return match ($ip) {
        '66.249.66.1' => true,
        '203.0.113.9' => false,
        default => null,
    };
};
$cl = new Classifier(null, null, $fakeRdns);
$c = $cl->classify($UA_H, ['accept' => 'text/html,application/xhtml+xml']);
ok($c['class'] === 'human', 'chrome is human');
$c = $cl->classify($UA_G);
ok($c['class'] === 'ai' && $c['name'] === 'GPTBot', 'gptbot');
$c = $cl->classify('Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)');
ok($c['class'] === 'ai' && $c['name'] === 'ClaudeBot', 'claudebot');
$c = $cl->classify('curl/8.5.0');
ok($c['class'] === 'automation' && $c['name'] === 'curl', 'curl');
ok($cl->classify('')['class'] === 'automation', 'empty UA');
ok($cl->classify('facebookexternalhit/1.1')['class'] === 'social', 'fb preview');
ok($cl->classify('Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)')['class'] === 'seo', 'ahrefs');
$c = $cl->classify('SomethingNewCrawler/2.0 (+https://example.com/bot)');
ok($c['class'] === 'bot' && $c['name'] === 'SomethingNewCrawler', 'unknown bot word');
$gb = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$c = $cl->classify($gb, [], '66.249.66.1');
ok($c['class'] === 'search' && $c['verified'] === true, 'googlebot real IP verifies');
$c = $cl->classify($gb, [], '203.0.113.9');
ok($c['class'] === 'automation' && $c['impersonation'], 'googlebot fake IP demoted');
$c = $cl->classify($gb, [], '');
ok($c['class'] === 'search' && $c['verified'] === null, 'no ip: claim kept, unverified');
$c = $cl->classify('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36', ['accept' => 'application/json']);
ok($c['class'] === 'automation', 'browser UA with json accept is a tool');
ok($cl->classify('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36', ['accept' => '*/*'])['class'] === 'human', 'browser UA with */* is human');
ok($cl->classify('JustSomeThing')['class'] === 'bot', 'unknown non-browser UA is bot');
ok(count(Classifier::shipped()) >= 80, 'catalog has 80+ agents');
// Real rDNS path must not throw for private/invalid addresses and never needs the network for them.
$real = new Classifier(new ArrayCache());
ok($real->verifyRdns('not-an-ip', ['.google.com']) === null, 'rdns invalid ip is null');

// ── Settings ────────────────────────────────────────────────────────────────
$s = Settings::normalize(['mode' => 'gate', 'pay_to' => '']);
ok($s['mode'] === 'monitor', 'gate without wallet falls back to monitor');
$s = Settings::normalize(['mode' => 'gate', 'pay_to' => $WALLET, 'price_usd' => 0.0001]);
ok($s['mode'] === 'gate' && $s['price_usd'] === 0.005, 'price floor 0.005');
$s = Settings::normalize(['class_policy' => ['ai' => 'block']]);
ok($s['class_policy']['ai'] === 'block' && $s['class_policy']['search'] === 'free', 'class_policy merges over defaults');
ok(Settings::validWallet($WALLET) && !Settings::validWallet('0x123'), 'wallet validation');

// ── X402 envelopes ──────────────────────────────────────────────────────────
$cfg = ['pay_to' => $WALLET, 'price_usd' => 0.01, 'facilitator_url' => '', 'facilitator_auth' => '', 'mpp_secret' => 'unit-secret', 'realm' => 'example.test', 'site_name' => 'Example', 'manifest_url' => 'https://example.test/.well-known/x402.json'];
$http = new FakeHttp(static fn () => ['status' => 500, 'body' => '']);
$x = new X402($cfg, $http);
$env = $x->envelope_v2('https://example.test/post/', 'Example: page');
ok($env['x402Version'] === 2 && $env['accepts'][0]['amount'] === '10000' && $env['accepts'][0]['network'] === 'eip155:8453', 'v2 envelope');
$b = $x->body_v1('https://example.test/post/', 'Example: page');
ok($b['x402Version'] === 1 && $b['accepts'][0]['network'] === 'base' && $b['accepts'][0]['maxAmountRequired'] === '10000' && $b['accepts'][0]['resource'] === 'https://example.test/post/', 'v1 body');
ok(X402::usd_to_units(0.005) === '5000' && X402::usd_to_units('0.01') === '10000', 'units');
ok($x->facilitator_url() === 'https://facilitator.payai.network', 'default facilitator');

// MPP challenge + credential round trip
$hdr = $x->mpp_challenge('Example: page');
ok(preg_match('/^Payment id="([^"]+)", realm="example.test", method="evm", intent="charge", expires="([^"]+)", request="([^"]+)"$/', $hdr, $m) === 1, 'mpp challenge header');
$req = X402::b64url_json($m[3]);
ok($req['amount'] === '10000' && $req['methodDetails']['chainId'] === 8453, 'mpp request');
$nonce = Keccak::hex($m[1] . 'example.test');
$cred = ['challenge' => ['id' => $m[1], 'realm' => 'example.test', 'method' => 'evm', 'intent' => 'charge', 'expires' => $m[2], 'request' => $m[3]], 'payload' => ['type' => 'authorization', 'from' => '0x' . str_repeat('a', 40), 'to' => $WALLET, 'value' => '10000', 'validAfter' => '0', 'validBefore' => (string) (time() + 300), 'nonce' => $nonce, 'signature' => '0xsig']];
$p = $x->extract_payment(['authorization' => 'Payment ' . X402::b64url(json_encode($cred))], 'https://example.test/post/');
ok($p['rail'] === 'mpp' && empty($p['error']) && $p['payload']['x402Version'] === 2 && $p['payload']['payload']['authorization']['nonce'] === $nonce, 'mpp credential accepted');
ok($x->precheck($p, 'https://example.test/post/') === null, 'mpp precheck');
$cred2 = $cred;
$cred2['payload']['nonce'] = '0x' . str_repeat('1', 64);
ok($x->extract_payment(['authorization' => 'Payment ' . X402::b64url(json_encode($cred2))], 'u')['error'] === 'verification-failed', 'mpp bad nonce rejected');
$cred3 = $cred;
$cred3['challenge']['id'] = 'tampered';
ok($x->extract_payment(['authorization' => 'Payment ' . X402::b64url(json_encode($cred3))], 'u')['error'] === 'invalid-challenge', 'mpp tampered id rejected');
$cred4 = $cred;
$cred4['challenge']['expires'] = gmdate('Y-m-d\TH:i:s\Z', time() - 10);
ok($x->extract_payment(['authorization' => 'Payment ' . X402::b64url(json_encode($cred4))], 'u')['error'] === 'payment-expired', 'mpp expired challenge rejected');
$other = new X402(['realm' => 'other.test'] + $cfg, $http);
ok($other->extract_payment(['authorization' => 'Payment ' . X402::b64url(json_encode($cred))], 'u')['error'] === 'invalid-challenge', 'mpp challenge from another realm rejected');

// v2 + v1 extraction & precheck
$auth = ['from' => '0x' . str_repeat('b', 40), 'to' => $WALLET, 'value' => '10000', 'validAfter' => '0', 'validBefore' => (string) (time() + 100), 'nonce' => '0x' . str_repeat('2', 64)];
$v2 = ['x402Version' => 2, 'accepted' => $x->requirements_v2('u'), 'payload' => ['signature' => '0xs', 'authorization' => $auth]];
$p = $x->extract_payment(['payment-signature' => base64_encode(json_encode($v2))], 'u');
ok($p['rail'] === 'x402v2' && $x->precheck($p, 'u') === null, 'v2 ok');
$v1 = ['x402Version' => 1, 'scheme' => 'exact', 'network' => 'base', 'payload' => ['signature' => '0xs', 'authorization' => $auth]];
$p = $x->extract_payment(['x-payment' => base64_encode(json_encode($v1))], 'u');
ok($p['rail'] === 'x402v1' && $x->precheck($p, 'u') === null, 'v1 ok');
$bad = $v2;
$bad['payload']['authorization']['to'] = '0x' . str_repeat('c', 40);
ok($x->precheck($x->extract_payment(['payment-signature' => base64_encode(json_encode($bad))], 'u'), 'u') === 'wrong_pay_to', 'wrong payTo');
$bad = $v2;
$bad['payload']['authorization']['value'] = '9999';
ok($x->precheck($x->extract_payment(['payment-signature' => base64_encode(json_encode($bad))], 'u'), 'u') === 'wrong_amount', 'wrong amount');
$bad = $v2;
$bad['payload']['authorization']['validBefore'] = '1';
ok($x->precheck($x->extract_payment(['payment-signature' => base64_encode(json_encode($bad))], 'u'), 'u') === 'authorization_expired', 'expired');
$bad = $v2;
$bad['accepted']['network'] = 'eip155:1';
ok($x->precheck($x->extract_payment(['payment-signature' => base64_encode(json_encode($bad))], 'u'), 'u') === 'wrong_network_or_asset', 'wrong network');
ok($x->extract_payment(['payment-signature' => 'zzz'], 'u')['error'] === 'malformed_payment_signature', 'garbage v2 header');
ok($x->extract_payment(['x-payment' => 'zzz'], 'u')['error'] === 'malformed_x_payment', 'garbage v1 header');
ok($x->extract_payment(['user-agent' => 'x'], 'u') === null, 'no payment header is null');

// facilitator flow: verify then settle, tx required
$http = new FakeHttp(static function (string $method, string $url, array $body): array {
    if (str_ends_with($url, '/verify')) {
        return ['status' => 200, 'body' => json_encode(['isValid' => true, 'payer' => '0xpayer'])];
    }
    return ['status' => 200, 'body' => json_encode(['success' => true, 'transaction' => '0xtx', 'network' => 'eip155:8453', 'payer' => '0xpayer'])];
});
$x = new X402($cfg, $http);
$p = $x->extract_payment(['payment-signature' => base64_encode(json_encode($v2))], 'https://example.test/x');
$r = $x->verify_and_settle($p, 'https://example.test/x');
ok($r['ok'] && $r['tx'] === '0xtx', 'settle ok');
ok(count($http->calls) === 2 && $http->calls[0][2]['x402Version'] === 2 && $http->calls[0][2]['paymentRequirements']['network'] === 'eip155:8453' && str_ends_with($http->calls[1][1], '/settle'), 'v2 facilitator body');
$http->calls = [];
$p = $x->extract_payment(['x-payment' => base64_encode(json_encode($v1))], 'https://example.test/x');
$r = $x->verify_and_settle($p, 'https://example.test/x');
ok($r['ok'] && $http->calls[0][2]['x402Version'] === 1 && $http->calls[0][2]['paymentRequirements']['network'] === 'base' && $http->calls[0][2]['paymentRequirements']['maxAmountRequired'] === '10000', 'v1 facilitator body');
$http->handler = static fn (string $m, string $u) => ['status' => 200, 'body' => json_encode(str_ends_with($u, '/verify') ? ['isValid' => true] : ['success' => true, 'transaction' => ''])];
$r = $x->verify_and_settle($p, 'u');
ok(!$r['ok'] && $r['reason'] === 'settlement_failed', 'empty tx is not a sale');
$http->handler = static fn () => ['status' => 200, 'body' => json_encode(['isValid' => false, 'invalidReason' => 'insufficient_funds'])];
$r = $x->verify_and_settle($p, 'u');
ok(!$r['ok'] && $r['reason'] === 'insufficient_funds', 'invalidReason surfaced');
$http->handler = static function (): array {
    throw new RuntimeException('timeout');
};
$r = $x->verify_and_settle($p, 'u');
ok($r['reason'] === 'facilitator_unreachable' && $r['detail'] === 'timeout', 'transport failure is unreachable');
$http->handler = static fn () => ['status' => 502, 'body' => '<html>bad gateway</html>'];
ok($x->verify_and_settle($p, 'u')['reason'] === 'facilitator_unreachable', 'non-JSON facilitator body is unreachable');

// ── Gate ────────────────────────────────────────────────────────────────────
$base = 'http://site.test';
function mkReq(string $method, string $path, string $ua, array $extra = [], string $ip = '198.51.100.7'): Request
{
    global $base;
    return new Request($method, $path, $base . $path, ['user-agent' => $ua] + $extra, $ip);
}
function mkGate(array $over = [], ?FakeHttp $http = null, ?MemStore $store = null, ?ArrayCache $cache = null): array
{
    global $WALLET, $fakeRdns;
    $http ??= new FakeHttp(static function (string $m, string $u): array {
        if (str_ends_with($u, '/verify')) {
            return ['status' => 200, 'body' => json_encode(['isValid' => true, 'payer' => '0xpayer'])];
        }
        return ['status' => 200, 'body' => json_encode(['success' => true, 'transaction' => '0x' . str_repeat('ab', 32), 'network' => 'eip155:8453', 'payer' => '0xpayer'])];
    });
    $store ??= new MemStore();
    $cache ??= new ArrayCache();
    $settings = $over + ['mode' => 'gate', 'pay_to' => $WALLET, 'price_usd' => 0.01, 'mpp_secret' => 'gate-secret', 'site_name' => 'Site'];
    return [new Gate($settings, $cache, $http, $store, new Classifier($cache, null, $fakeRdns)), $http, $store, $cache];
}
function auth(string $value = '10000', ?string $to = null): array
{
    global $WALLET;
    return ['from' => '0x' . str_repeat('b', 40), 'to' => $to ?? $WALLET, 'value' => $value, 'validAfter' => '0', 'validBefore' => (string) (time() + 300), 'nonce' => '0x' . bin2hex(random_bytes(32))];
}
function v2header(array $a): string
{
    global $WALLET, $base;
    return base64_encode(json_encode(['x402Version' => 2, 'resource' => ['url' => $base . '/p'], 'accepted' => ['scheme' => 'exact', 'network' => 'eip155:8453', 'amount' => '10000', 'asset' => X402::USDC, 'payTo' => $WALLET, 'maxTimeoutSeconds' => 300, 'extra' => ['name' => 'USD Coin', 'version' => '2']], 'payload' => ['signature' => '0xsig', 'authorization' => $a]]));
}

[$g, $http, $store] = mkGate();
$r = $g->handle(mkReq('GET', '/p', $UA_H, ['accept' => 'text/html']));
ok(!$r->respond && $store->last()['status'] === 'passed' && $store->last()['ua_class'] === 'human', 'human passes and is logged');
ok(isset($r->passHeaders['Link']) && str_contains($r->passHeaders['Link'], 'x402.json'), 'human pass carries discovery Link');

$r = $g->handle(mkReq('GET', '/p', $UA_G));
ok($r->respond && $r->status === 402, 'gptbot gets 402');
$pr = json_decode(base64_decode($r->headers['PAYMENT-REQUIRED']), true);
ok($pr['x402Version'] === 2 && $pr['accepts'][0]['network'] === 'eip155:8453' && $pr['accepts'][0]['amount'] === '10000' && $pr['accepts'][0]['payTo'] === $WALLET && $pr['resource']['url'] === $base . '/p', 'PAYMENT-REQUIRED v2 header');
$b1 = json_decode($r->body, true);
ok($b1['x402Version'] === 1 && $b1['accepts'][0]['network'] === 'base' && $b1['accepts'][0]['maxAmountRequired'] === '10000' && $b1['accepts'][0]['resource'] === $base . '/p', 'v1 JSON body');
ok(preg_match('/^Payment id="[^"]+", realm="site.test", method="evm", intent="charge"/', $r->headers['WWW-Authenticate']) === 1, 'MPP WWW-Authenticate');
ok($r->headers['Content-Type'] === 'application/json; charset=utf-8' && str_contains($r->headers['Cache-Control'], 'no-store') && str_contains($r->headers['Link'], 'describedby'), '402 content-type, no-store, Link');
ok($store->last()['status'] === 'unpaid' && $store->last()['bot_name'] === 'GPTBot' && strlen($store->last()['ip_hash']) === 16, 'unpaid hit logged with hashed ip');
ok(!str_contains(json_encode($store->last()), '198.51.100.7'), 'raw IP never stored');

$r = $g->handle(mkReq('HEAD', '/p', $UA_G));
ok($r->status === 402 && $r->body === '' && isset($r->headers['PAYMENT-REQUIRED']), 'HEAD gets 402 without body');
ok($g->handle(mkReq('GET', '/', 'curl/8.5'))->status === 402, 'curl on home gets 402');
ok($g->handle(mkReq('GET', '/x?id=1', 'curl/8.5'))->status === 402, 'curl with query gets 402');
$r = $g->handle(mkReq('GET', '/x?id=1', 'curl/8.5'));
ok(json_decode($r->body, true)['accepts'][0]['resource'] === $base . '/x?id=1', 'resource keeps the query string');
ok(!$g->handle(mkReq('GET', '/p', 'facebookexternalhit/1.1'))->respond, 'social preview free');
ok(!$g->handle(mkReq('GET', '/p', $gb, [], '66.249.66.1'))->respond && $store->last()['verified'] === true, 'verified googlebot free');
ok($g->handle(mkReq('GET', '/p', $gb, [], '203.0.113.9'))->status === 402, 'impersonated googlebot is charged');
foreach (['/robots.txt', '/llms.txt', '/agents.txt', '/.well-known/x402.json', '/favicon.ico', '/sitemap.xml'] as $path) {
    ok(!$g->handle(mkReq('GET', $path, $UA_G))->respond, "exempt $path");
}

// monitor mode never charges but records what it would have earned
[$gm, , $sm] = mkGate(['mode' => 'monitor']);
$r = $gm->handle(mkReq('GET', '/p', $UA_G));
ok(!$r->respond && $sm->last()['status'] === 'monitor' && $sm->last()['amount_units'] === 10000, 'monitor mode passes and logs would-be revenue');
// gate with no wallet degrades to monitor instead of issuing unpayable 402s
[$gn] = mkGate(['pay_to' => '']);
ok(!$gn->handle(mkReq('GET', '/p', $UA_G))->respond, 'gate without wallet never 402s');

// policy: block, override, allow_paths, exempt_paths
[$gb2, , $sb] = mkGate(['class_policy' => ['ai' => 'block']]);
$r = $gb2->handle(mkReq('GET', '/p', $UA_G));
ok($r->status === 403 && $sb->last()['status'] === 'blocked', 'class policy block returns 403');
[$go] = mkGate(['bot_overrides' => ['GPTBot' => 'free', 'curl' => 'block']]);
ok(!$go->handle(mkReq('GET', '/p', $UA_G))->respond, 'bot override free beats class paid');
ok($go->handle(mkReq('GET', '/p', 'curl/8'))->status === 403, 'bot override block');
[$ga] = mkGate(['allow_paths' => ['/public'], 'exempt_paths' => ['/admin']]);
ok(!$ga->handle(mkReq('GET', '/public/a', $UA_G))->respond && !$ga->handle(mkReq('GET', '/public', $UA_G))->respond && !$ga->handle(mkReq('GET', '/admin/x', $UA_G))->respond, 'allow_paths and exempt_paths match on segment boundaries');
ok($ga->handle(mkReq('GET', '/publicity', 'curl/8'))->respond, 'allow /public does not exempt /publicity');
[$gp] = mkGate(['allow_paths' => ['/pricing']]);
ok(!$gp->handle(mkReq('GET', '/pricing/plans', $UA_G))->respond && $gp->handle(mkReq('GET', '/pricing-secret', $UA_G))->status === 402, 'allow /pricing exempts /pricing/plans but gates /pricing-secret');
foreach (['/robots.txt.html', '/robots.txt/x', '/sitemapfoo', '/sitemap/x.xml', '/.well-known/../post', '/.well-known/%2e%2e/post', '/%2e%2e/post', '/llms.txt%2f..', '/.well-known%2fx', '//llms.txt', '/a/../robots.txt', '/./robots.txt', '/favicon.ico.php'] as $evil) {
    ok($gp->handle(mkReq('GET', $evil, 'curl/8'))->status === 402, "not exempt: $evil");
}
foreach (['/robots.txt', '/robots.txt/', '/llms.txt', '/agents.txt', '/favicon.ico', '/sitemap.xml', '/sitemap-posts_1.xml', '/post-sitemap.xml', '/.well-known/x402.json', '/.well-known/security.txt'] as $good) {
    ok(!$gp->handle(mkReq('GET', $good, 'curl/8'))->respond, "exempt: $good");
}
[$gh] = mkGate(['log_humans' => false]);
$gh->handle(mkReq('GET', '/p', $UA_H, ['accept' => 'text/html']));
ok(true, 'log_humans=false does not crash');
$sh = new MemStore();
[$gh, , $sh] = mkGate(['log_humans' => false], null, $sh);
$gh->handle(mkReq('GET', '/p', $UA_H, ['accept' => 'text/html']));
ok(count($sh->rows) === 0, 'log_humans=false writes nothing');

// trusted IP header
[$gt, , $st] = mkGate(['trusted_ip_header' => 'CF-Connecting-IP']);
$gt->handle(mkReq('GET', '/p', $UA_G, ['cf-connecting-ip' => '203.0.113.50, 10.0.0.1']));
$h1 = $st->last()['ip_hash'];
$gt->handle(mkReq('GET', '/p', $UA_G, ['cf-connecting-ip' => '203.0.113.51']));
ok($h1 !== $st->last()['ip_hash'], 'trusted_ip_header changes the source identity');
$gt->handle(mkReq('GET', '/p', $UA_G, ['cf-connecting-ip' => 'garbage']));
$gt->handle(mkReq('GET', '/p', $UA_G));
ok($st->rows[2]['ip_hash'] === $st->rows[3]['ip_hash'], 'invalid trusted header value falls back to REMOTE_ADDR');

// spoofed left-most X-Forwarded-For entry must not buy the search-engine lane
[$gx2, , $sx2] = mkGate(['trusted_ip_header' => 'X-Forwarded-For']);
$r = $gx2->handle(mkReq('GET', '/p', $gb, ['x-forwarded-for' => '66.249.66.1, 203.0.113.9'], '10.0.0.1'));
ok($r->status === 402 && $sx2->last()['ua_class'] === 'automation' && $sx2->last()['verified'] === false, 'XFF: spoofed left-most Googlebot IP is ignored (right-most used)');
$r = $gx2->handle(mkReq('GET', '/p', $gb, ['x-forwarded-for' => '203.0.113.9, 66.249.66.1'], '10.0.0.1'));
ok(!$r->respond && $sx2->last()['verified'] === true, 'XFF: proxy-appended right-most Googlebot IP verifies');

// paid v2
[$g, $http, $store, $cache] = mkGate();
$a = auth();
$h = v2header($a);
$r = $g->handle(mkReq('GET', '/p', $UA_G, ['payment-signature' => $h]));
ok(!$r->respond && isset($r->passHeaders['PAYMENT-RESPONSE']), 'v2 paid passes with receipt');
$rec = json_decode(base64_decode($r->passHeaders['PAYMENT-RESPONSE']), true);
ok($rec['success'] === true && str_starts_with($rec['transaction'], '0xabab') && $r->passHeaders['X-PAYMENT-RESPONSE'] === $r->passHeaders['PAYMENT-RESPONSE'], 'receipt mirrored on both header names');
ok($store->last()['status'] === 'paid' && $store->last()['amount_units'] === 10000 && $store->last()['rail'] === 'x402v2' && $store->last()['tx_hash'] !== '', 'paid hit logged with tx hash');
ok(count($http->calls) === 2 && str_ends_with($http->calls[0][1], '/verify') && str_ends_with($http->calls[1][1], '/settle'), 'verify then settle, once each');
$r = $g->handle(mkReq('GET', '/p', $UA_G, ['payment-signature' => $h]));
ok($r->status === 402 && str_contains($r->body, 'payment_in_flight'), 'replayed nonce rejected');
ok(count($http->calls) === 2, 'replay never reaches the facilitator');
// paid v1
$a1 = auth();
$v1h = base64_encode(json_encode(['x402Version' => 1, 'scheme' => 'exact', 'network' => 'base', 'payload' => ['signature' => '0xsig', 'authorization' => $a1]]));
$r = $g->handle(mkReq('GET', '/p', 'x402-fetch/1.2.0', ['x-payment' => $v1h]));
ok(!$r->respond && $store->last()['rail'] === 'x402v1', 'v1 paid passes');
ok($http->calls[count($http->calls) - 1][2]['x402Version'] === 1 && $http->calls[count($http->calls) - 1][2]['paymentRequirements']['network'] === 'base', 'v1 forwarded as v1');
// underpayment / wrong payTo
$low = base64_encode(json_encode(['x402Version' => 1, 'scheme' => 'exact', 'network' => 'base', 'payload' => ['signature' => '0xsig', 'authorization' => auth('5000')]]));
$r = $g->handle(mkReq('GET', '/p', 'x402-fetch/1.2.0', ['x-payment' => $low]));
ok($r->status === 402 && str_contains($r->body, 'wrong_amount') && $store->last()['status'] === 'rejected', 'underpayment rejected');
$wrongTo = v2header(auth('10000', '0x' . str_repeat('c', 40)));
$r = $g->handle(mkReq('GET', '/p', $UA_G, ['payment-signature' => $wrongTo]));
ok($r->status === 402 && str_contains($r->body, 'wrong_pay_to'), 'wrong payTo rejected');
// garbage and type-confusion payloads never crash the gate
$evil = base64_encode(json_encode(['x402Version' => 2, 'accepted' => ['network' => ['x'], 'asset' => ['y']], 'payload' => ['authorization' => ['to' => ['arr'], 'value' => ['arr'], 'nonce' => ['n']]]]));
$r = $g->handle(mkReq('GET', '/p', $UA_G, ['payment-signature' => $evil]));
ok($r->status === 402, 'type-confused payment is a 402, not a crash');
$r = $g->handle(mkReq('GET', '/p', $UA_G, ['payment-signature' => 'not-base64!!']));
ok($r->status === 402 && str_contains($r->body, 'malformed_payment_signature'), 'garbage payment header is a 402');
// MPP
$r0 = $g->handle(mkReq('GET', '/p', $UA_G));
$wa = $r0->headers['WWW-Authenticate'];
preg_match('/id="([^"]+)".*expires="([^"]+)".*request="([^"]+)"/', $wa, $m);
$nonce = Keccak::hex($m[1] . 'site.test');
$cred = ['challenge' => ['id' => $m[1], 'realm' => 'site.test', 'method' => 'evm', 'intent' => 'charge', 'expires' => $m[2], 'request' => $m[3]], 'payload' => ['type' => 'authorization', 'from' => '0x' . str_repeat('d', 40), 'to' => $WALLET, 'value' => '10000', 'validAfter' => '0', 'validBefore' => (string) (time() + 300), 'nonce' => $nonce, 'signature' => '0xsig']];
$mh = ['authorization' => 'Payment ' . X402::b64url(json_encode($cred))];
$r = $g->handle(mkReq('GET', '/p', 'mpp-client/1', $mh));
ok(!$r->respond && isset($r->passHeaders['Payment-Receipt']) && $store->last()['rail'] === 'mpp', 'MPP paid passes with Payment-Receipt');
$rcpt = X402::b64url_json($r->passHeaders['Payment-Receipt']);
ok($rcpt['status'] === 'success' && $rcpt['reference'] === $store->last()['tx_hash'], 'MPP receipt references the tx');
$r = $g->handle(mkReq('GET', '/p', 'mpp-client/1', $mh));
ok($r->status === 402 && $r->headers['Content-Type'] === 'application/problem+json; charset=utf-8', 'MPP replay is problem+json');
// payment on a free class is honoured
$r = $g->handle(mkReq('GET', '/p', 'facebookexternalhit/1.1', ['payment-signature' => v2header(auth())]));
ok(!$r->respond && $store->last()['status'] === 'paid', 'payment from a free class is still taken');

// facilitator failure modes
$rejectHttp = new FakeHttp(static fn () => ['status' => 200, 'body' => json_encode(['isValid' => false, 'invalidReason' => 'insufficient_funds'])]);
[$gr, , $sr] = mkGate([], $rejectHttp);
$r = $gr->handle(mkReq('GET', '/p', $UA_G, ['payment-signature' => v2header(auth())]));
ok($r->status === 402 && str_contains($r->body, 'insufficient_funds') && $sr->last()['status'] === 'settle_failed', 'facilitator reject becomes 402 with reason');
$notx = new FakeHttp(static fn (string $m, string $u) => ['status' => 200, 'body' => json_encode(str_ends_with($u, '/verify') ? ['isValid' => true] : ['success' => true, 'transaction' => ''])]);
[$gx, , $sx] = mkGate([], $notx);
$r = $gx->handle(mkReq('GET', '/p', $UA_G, ['payment-signature' => v2header(auth())]));
ok($r->status === 402 && $sx->last()['status'] === 'settle_failed', 'no tx hash means no content');
$down = new FakeHttp(static function (): array {
    throw new RuntimeException('connection refused');
});
[$gd, , $sd, $cd] = mkGate([], $down);
$ah = v2header($an = auth());
$r = $gd->handle(mkReq('GET', '/p', $UA_G, ['payment-signature' => $ah]));
ok($r->status === 402 && str_contains($r->body, 'facilitator_unreachable'), 'facilitator down is 402');
$down->handler = static fn (string $m, string $u) => ['status' => 200, 'body' => json_encode(str_ends_with($u, '/verify') ? ['isValid' => true, 'payer' => '0xp'] : ['success' => true, 'transaction' => '0xdead', 'payer' => '0xp'])];
$r = $gd->handle(mkReq('GET', '/p', $UA_G, ['payment-signature' => $ah]));
ok(!$r->respond, 'same authorization can be retried after the facilitator was unreachable');

// site_url override pins origin and realm behind a proxy
[$gs] = mkGate(['site_url' => 'https://example.com']);
$r = $gs->handle(mkReq('GET', '/p?a=1', $UA_G));
$pr = json_decode(base64_decode($r->headers['PAYMENT-REQUIRED']), true);
ok($pr['resource']['url'] === 'https://example.com/p?a=1' && str_contains($r->headers['WWW-Authenticate'], 'realm="example.com"'), 'site_url pins resource URL and MPP realm');

// ── Discovery ───────────────────────────────────────────────────────────────
$d = new Discovery(['mode' => 'gate', 'pay_to' => $WALLET, 'price_usd' => 0.01, 'site_name' => 'Site', 'site_description' => 'A site', 'llms_pages' => [['Hello', 'http://site.test/hello']]], 'http://site.test');
$man = json_decode($d->resolve('/.well-known/x402.json')['body'], true);
ok($man['x402Version'] === 2 && $man['resources'][0]['accepts'][0]['payTo'] === $WALLET && $man['resources'][0]['metadata']['paid'] === true && $man['provider']['contact'] === null, 'manifest content');
ok(str_contains($d->resolve('/llms.txt')['body'], '[Hello](http://site.test/hello)') && str_contains($d->resolve('/llms.txt/')['body'], 'Pay $0.01 USDC'), 'llms.txt lists pages and price');
ok(str_contains($d->resolve('/agents.txt')['body'], 'Payments: required') && str_contains($d->resolve('/agents.txt')['body'], 'PayTo: ' . $WALLET), 'agents.txt');
ok($d->resolve('/nope') === null, 'unknown path is not discovery');
ok(str_contains($d->robotsLines(), '/.well-known/x402.json'), 'robots lines');
$dm = new Discovery(['mode' => 'monitor', 'site_email' => 'a@b.c', 'publish_contact' => true], 'http://site.test');
ok(str_contains($dm->resolve('/agents.txt')['body'], 'Payments: none') && json_decode($dm->resolve('/.well-known/x402.json')['body'], true)['resources'][0]['metadata']['paid'] === false, 'monitor mode advertises no payment');
ok(json_decode($dm->resolve('/.well-known/x402.json')['body'], true)['provider']['contact'] === 'a@b.c', 'contact only when published');
ok((new Discovery(['discovery' => false], 'http://site.test'))->resolve('/llms.txt') === null && (new Discovery(['discovery' => false], 'http://site.test'))->robotsLines() === '', 'discovery can be disabled');
ok(Discovery::headers('text/plain')['Access-Control-Allow-Origin'] === '*', 'discovery CORS header');

// ── Pro ─────────────────────────────────────────────────────────────────────
ok(Pro::enabled(['pro_key' => 'fmppc_' . str_repeat('a', 30)]) && !Pro::enabled(['pro_key' => 'nope']) && !Pro::enabled([]), 'pro key shape');
$intel = [
    ['name' => 'GPTBot', 'class' => 'ai', 'match' => ['gptbot', 'gptbot-next'], 'verify' => []],
    ['name' => 'Brand New Crawler', 'class' => 'seo', 'match' => ['BrandNewCrawler/'], 'verify' => []],
    ['name' => 'Evil', 'class' => 'god', 'match' => ['x']],
    ['name' => '', 'class' => 'ai', 'match' => ['x']],
];
$clean = Pro::cleanIntel($intel);
ok(count($clean) === 2 && $clean[1]['match'] === ['brandnewcrawler/'], 'intel feed is validated and lowercased');
$merged = Classifier::mergeCatalog(Classifier::shipped(), $clean);
$names = array_column($merged, 'name');
ok(in_array('Brand New Crawler', $names, true) && count(array_keys($names, 'GPTBot', true)) === 1, 'intel appends new agent, no duplicate');
$g2 = $merged[array_search('GPTBot', $names, true)];
ok(in_array('gptbot-next', $g2['match'], true) && in_array('gptbot', $g2['match'], true), 'intel extends match list');

$key = 'fmppc_' . str_repeat('k', 30);
$proHttp = new FakeHttp(static function (string $m, string $u, array $b): array {
    if (str_ends_with($u, '/license/verify')) {
        return ['status' => 200, 'body' => json_encode(['ok' => true, 'status' => 'active', 'sites_used' => 1, 'site_limit' => 5])];
    }
    if (str_ends_with($u, '/ingest')) {
        return ['status' => 200, 'body' => json_encode(['ok' => true])];
    }
    return ['status' => 200, 'body' => json_encode(['version' => 'v1', 'agents' => [['name' => 'NewBot', 'class' => 'ai', 'match' => ['newbot']]]])];
});
$pro = new Pro(['pro_key' => $key, 'mode' => 'gate', 'price_usd' => 0.01, 'pay_to' => $WALLET], $proHttp, 'site.test', 'https://site.test/');
$ms = new MemStore();
$ms->record(['status' => 'paid', 'ts' => '2026-10-17 00:00:00', 'bot_name' => 'GPTBot', 'ua_class' => 'ai', 'path' => '/p', 'rail' => 'x402v2', 'amount_units' => 10000, 'tx_hash' => '0xabc', 'payer' => '0xp']);
$state = $pro->sync($ms, [], 'Site');
$ingest = array_values(array_filter($proHttp->calls, static fn ($c) => str_ends_with($c[1], '/ingest')))[0] ?? null;
ok($state['ok'] === true && $state['sync_ok'] === true && $state['last_settlement_id'] === 1 && $state['intel_count'] === 1, 'pro sync verifies, ingests, pulls intel');
ok($ingest && $ingest[2]['site']['host'] === 'site.test' && count($ingest[2]['daily']) === 2 && $ingest[2]['settlements'][0]['tx_hash'] === '0xabc' && !isset($ingest[2]['settlements'][0]['id']), 'ingest payload shape');
ok(str_starts_with($ingest[3]['Authorization'], 'Bearer fmppc_'), 'pro uses bearer license');
$n = count($proHttp->calls);
$state2 = $pro->sync($ms, $state, 'Site');
ok(count($proHttp->calls) === $n + 1 && $state2['last_settlement_id'] === 1, 'second pass only ingests (verify and intel are daily)');
$off = new Pro(['pro_key' => ''], $proHttp, 'site.test', 'https://site.test/');
$c0 = count($proHttp->calls);
$off->sync($ms, [], 'Site');
ok(count($proHttp->calls) === $c0, 'no license key means no outbound call');
$badHttp = new FakeHttp(static fn () => ['status' => 401, 'body' => json_encode(['error' => 'bad key'])]);
$bad = (new Pro(['pro_key' => $key, 'mode' => 'gate', 'price_usd' => 0.01, 'pay_to' => ''], $badHttp, 'site.test', 'https://site.test/'))->sync($ms, [], 'Site');
ok($bad['ok'] === false && count($badHttp->calls) === 1, 'invalid license stops after verify');

// ── ArrayCache ──────────────────────────────────────────────────────────────
$ac = new ArrayCache();
ok($ac->add('k', 1, 60) && !$ac->add('k', 2, 60) && $ac->get('k') === 1, 'cache add is first-writer-wins');
$ac->delete('k');
ok($ac->get('k') === null && $ac->add('k', 3, 60), 'cache delete releases the key');
$ac->set('e', 1, -1);
ok($ac->get('e') === null, 'expired entry is gone');

echo "pass=$pass fail=$fail\n";
exit($fail ? 1 : 0);
