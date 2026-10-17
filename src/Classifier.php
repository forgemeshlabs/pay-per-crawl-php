<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl;

use ForgeMesh\PayPerCrawl\Contracts\CacheInterface;

/**
 * Request classifier: who is asking, and is it who it says it is.
 *
 * Classes: human, search, social, monitor, seo, ai, automation, bot.
 * Search engines are verified by reverse + forward DNS; an unverifiable claim is
 * demoted to "automation" and flagged as impersonation.
 */
final class Classifier
{
    /** @var list<array<string,mixed>> */
    private array $catalog;

    /** @var callable(string,list<string>):?bool */
    private $rdns;

    /**
     * @param list<array<string,mixed>>|null $catalog  defaults to shipped()
     * @param callable(string,list<string>):?bool|null $rdns  override the DNS check (tests)
     */
    public function __construct(private ?CacheInterface $cache = null, ?array $catalog = null, ?callable $rdns = null)
    {
        $this->catalog = $catalog ?? self::shipped();
        $this->rdns = $rdns ?? [$this, 'verifyRdns'];
    }

    /** The list that ships with the package: name, class, match (UA substrings), verify (rDNS suffixes). */
    public static function shipped(): array
    {
        return [
            // Search engines (free by default; verified by rDNS).
            array( 'name' => 'Googlebot', 'class' => 'search', 'match' => array( 'googlebot' ), 'verify' => array( '.googlebot.com', '.google.com' ) ),
            array( 'name' => 'Google-InspectionTool', 'class' => 'search', 'match' => array( 'google-inspectiontool' ), 'verify' => array( '.googlebot.com', '.google.com' ) ),
            array( 'name' => 'GoogleOther', 'class' => 'ai', 'match' => array( 'googleother' ), 'verify' => array( '.googlebot.com', '.google.com' ) ),
            array( 'name' => 'Bingbot', 'class' => 'search', 'match' => array( 'bingbot' ), 'verify' => array( '.search.msn.com' ) ),
            array( 'name' => 'DuckDuckBot', 'class' => 'search', 'match' => array( 'duckduckbot' ), 'verify' => array() ),
            array( 'name' => 'Applebot', 'class' => 'search', 'match' => array( 'applebot' ), 'verify' => array( '.applebot.apple.com' ) ),
            array( 'name' => 'YandexBot', 'class' => 'search', 'match' => array( 'yandexbot', 'yandex.com/bots' ), 'verify' => array( '.yandex.ru', '.yandex.net', '.yandex.com' ) ),
            array( 'name' => 'Baiduspider', 'class' => 'search', 'match' => array( 'baiduspider' ), 'verify' => array( '.baidu.com', '.baidu.jp' ) ),
            array( 'name' => 'Yahoo Slurp', 'class' => 'search', 'match' => array( 'yahoo! slurp' ), 'verify' => array( '.crawl.yahoo.net' ) ),
            array( 'name' => 'SeznamBot', 'class' => 'search', 'match' => array( 'seznambot' ), 'verify' => array( '.seznam.cz' ) ),
            array( 'name' => 'Qwantbot', 'class' => 'search', 'match' => array( 'qwantbot', 'qwantify' ), 'verify' => array() ),

            // AI crawlers and assistants (paid by default).
            array( 'name' => 'GPTBot', 'class' => 'ai', 'match' => array( 'gptbot' ) ),
            array( 'name' => 'ChatGPT-User', 'class' => 'ai', 'match' => array( 'chatgpt-user' ) ),
            array( 'name' => 'OAI-SearchBot', 'class' => 'ai', 'match' => array( 'oai-searchbot' ) ),
            array( 'name' => 'ClaudeBot', 'class' => 'ai', 'match' => array( 'claudebot', 'anthropic-ai' ) ),
            array( 'name' => 'Claude-User', 'class' => 'ai', 'match' => array( 'claude-user' ) ),
            array( 'name' => 'Claude-SearchBot', 'class' => 'ai', 'match' => array( 'claude-searchbot' ) ),
            array( 'name' => 'PerplexityBot', 'class' => 'ai', 'match' => array( 'perplexitybot' ) ),
            array( 'name' => 'Perplexity-User', 'class' => 'ai', 'match' => array( 'perplexity-user' ) ),
            array( 'name' => 'Google-Extended', 'class' => 'ai', 'match' => array( 'google-extended' ) ),
            array( 'name' => 'Gemini-Deep-Research', 'class' => 'ai', 'match' => array( 'gemini-deep-research' ) ),
            array( 'name' => 'Amazonbot', 'class' => 'ai', 'match' => array( 'amazonbot' ) ),
            array( 'name' => 'Applebot-Extended', 'class' => 'ai', 'match' => array( 'applebot-extended' ) ),
            array( 'name' => 'Meta-ExternalAgent', 'class' => 'ai', 'match' => array( 'meta-externalagent', 'meta-externalfetcher', 'facebookbot' ) ),
            array( 'name' => 'Bytespider', 'class' => 'ai', 'match' => array( 'bytespider', 'tiktokspider' ) ),
            array( 'name' => 'CCBot', 'class' => 'ai', 'match' => array( 'ccbot' ) ),
            array( 'name' => 'cohere-ai', 'class' => 'ai', 'match' => array( 'cohere-ai', 'cohere-training-data-crawler' ) ),
            array( 'name' => 'MistralAI-User', 'class' => 'ai', 'match' => array( 'mistralai-user' ) ),
            array( 'name' => 'DuckAssistBot', 'class' => 'ai', 'match' => array( 'duckassistbot' ) ),
            array( 'name' => 'YouBot', 'class' => 'ai', 'match' => array( 'youbot' ) ),
            array( 'name' => 'Diffbot', 'class' => 'ai', 'match' => array( 'diffbot' ) ),
            array( 'name' => 'ImagesiftBot', 'class' => 'ai', 'match' => array( 'imagesiftbot' ) ),
            array( 'name' => 'Omgili', 'class' => 'ai', 'match' => array( 'omgili', 'webzio' ) ),
            array( 'name' => 'AI2Bot', 'class' => 'ai', 'match' => array( 'ai2bot' ) ),
            array( 'name' => 'PetalBot', 'class' => 'ai', 'match' => array( 'petalbot' ) ),
            array( 'name' => 'Timpibot', 'class' => 'ai', 'match' => array( 'timpibot' ) ),
            array( 'name' => 'iAskBot', 'class' => 'ai', 'match' => array( 'iaskspider', 'iaskbot' ) ),
            array( 'name' => 'Kagibot', 'class' => 'ai', 'match' => array( 'kagibot' ) ),
            array( 'name' => 'DeepSeekBot', 'class' => 'ai', 'match' => array( 'deepseekbot' ) ),
            array( 'name' => 'Grok', 'class' => 'ai', 'match' => array( 'xai-grok', 'grokbot' ) ),
            array( 'name' => 'Brave Search', 'class' => 'ai', 'match' => array( 'bravebot' ) ),
            array( 'name' => 'Firecrawl', 'class' => 'ai', 'match' => array( 'firecrawl' ) ),
            array( 'name' => 'Jina Reader', 'class' => 'ai', 'match' => array( 'jina', 'readerbot' ) ),
            array( 'name' => 'Exa', 'class' => 'ai', 'match' => array( 'exabot', 'exa.ai' ) ),
            array( 'name' => 'Tavily', 'class' => 'ai', 'match' => array( 'tavily' ) ),
            array( 'name' => 'Browserbase', 'class' => 'ai', 'match' => array( 'browserbase' ) ),

            // SEO crawlers (paid by default: commercial, high volume).
            array( 'name' => 'AhrefsBot', 'class' => 'seo', 'match' => array( 'ahrefsbot' ) ),
            array( 'name' => 'SemrushBot', 'class' => 'seo', 'match' => array( 'semrushbot' ) ),
            array( 'name' => 'MJ12bot', 'class' => 'seo', 'match' => array( 'mj12bot' ) ),
            array( 'name' => 'DotBot', 'class' => 'seo', 'match' => array( 'dotbot' ) ),
            array( 'name' => 'DataForSeoBot', 'class' => 'seo', 'match' => array( 'dataforseobot' ) ),
            array( 'name' => 'BLEXBot', 'class' => 'seo', 'match' => array( 'blexbot' ) ),
            array( 'name' => 'Screaming Frog', 'class' => 'seo', 'match' => array( 'screaming frog' ) ),
            array( 'name' => 'SiteAuditBot', 'class' => 'seo', 'match' => array( 'siteauditbot' ) ),

            // Social link previews (free by default: humans see these).
            array( 'name' => 'Facebook preview', 'class' => 'social', 'match' => array( 'facebookexternalhit' ) ),
            array( 'name' => 'Twitterbot', 'class' => 'social', 'match' => array( 'twitterbot' ) ),
            array( 'name' => 'LinkedInBot', 'class' => 'social', 'match' => array( 'linkedinbot' ) ),
            array( 'name' => 'Slackbot', 'class' => 'social', 'match' => array( 'slackbot', 'slack-imgproxy' ) ),
            array( 'name' => 'Discordbot', 'class' => 'social', 'match' => array( 'discordbot' ) ),
            array( 'name' => 'TelegramBot', 'class' => 'social', 'match' => array( 'telegrambot' ) ),
            array( 'name' => 'WhatsApp', 'class' => 'social', 'match' => array( 'whatsapp' ) ),
            array( 'name' => 'Pinterestbot', 'class' => 'social', 'match' => array( 'pinterestbot', 'pinterest/' ) ),
            array( 'name' => 'Redditbot', 'class' => 'social', 'match' => array( 'redditbot' ) ),
            array( 'name' => 'Mastodon', 'class' => 'social', 'match' => array( 'mastodon' ) ),
            array( 'name' => 'Bluesky', 'class' => 'social', 'match' => array( 'bluesky cardyb' ) ),
            array( 'name' => 'iMessage preview', 'class' => 'social', 'match' => array( 'facebot twitterbot' ) ),

            // Monitors and feed readers (free by default).
            array( 'name' => 'UptimeRobot', 'class' => 'monitor', 'match' => array( 'uptimerobot' ) ),
            array( 'name' => 'Pingdom', 'class' => 'monitor', 'match' => array( 'pingdom' ) ),
            array( 'name' => 'StatusCake', 'class' => 'monitor', 'match' => array( 'statuscake' ) ),
            array( 'name' => 'Better Uptime', 'class' => 'monitor', 'match' => array( 'betteruptime', 'better uptime' ) ),
            array( 'name' => 'Feed reader', 'class' => 'monitor', 'match' => array( 'feedly', 'feedbin', 'inoreader', 'newsblur', 'feedfetcher' ) ),
            array( 'name' => 'Internet Archive', 'class' => 'monitor', 'match' => array( 'archive.org_bot', 'ia_archiver' ) ),
            array( 'name' => 'WordPress', 'class' => 'monitor', 'match' => array( 'wordpress/' ) ),

            // Generic automation (paid by default).
            array( 'name' => 'curl', 'class' => 'automation', 'match' => array( 'curl/' ) ),
            array( 'name' => 'wget', 'class' => 'automation', 'match' => array( 'wget/' ) ),
            array( 'name' => 'python-requests', 'class' => 'automation', 'match' => array( 'python-requests', 'python-urllib', 'aiohttp', 'httpx/' ) ),
            array( 'name' => 'Scrapy', 'class' => 'automation', 'match' => array( 'scrapy' ) ),
            array( 'name' => 'Go-http-client', 'class' => 'automation', 'match' => array( 'go-http-client' ) ),
            array( 'name' => 'Node fetch', 'class' => 'automation', 'match' => array( 'node-fetch', 'undici', 'axios/', 'got (' ) ),
            array( 'name' => 'Java HTTP', 'class' => 'automation', 'match' => array( 'java/', 'okhttp', 'apache-httpclient' ) ),
            array( 'name' => 'Headless browser', 'class' => 'automation', 'match' => array( 'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium' ) ),
            array( 'name' => 'HTTPie', 'class' => 'automation', 'match' => array( 'httpie' ) ),
            array( 'name' => 'PostmanRuntime', 'class' => 'automation', 'match' => array( 'postmanruntime' ) ),
            array( 'name' => 'libwww', 'class' => 'automation', 'match' => array( 'libwww', 'lwp-trivial' ) ),
            array( 'name' => 'x402 client', 'class' => 'automation', 'match' => array( 'x402', 'mpp/' ) ),
        ];
    }

    /** @return list<string> */
    public static function classes(): array
    {
        return ['human', 'search', 'social', 'monitor', 'seo', 'ai', 'automation', 'bot'];
    }

    /**
     * @param array<string,string> $headers lowercased header map
     * @return array{class:string,name:string,verified:?bool,impersonation:bool}
     */
    public function classify(string $ua, array $headers = [], string $ip = ''): array
    {
        $uaL = strtolower(trim($ua));

        foreach ($this->catalog as $entry) {
            foreach ($entry['match'] as $needle) {
                if (str_contains($uaL, $needle)) {
                    $class = $entry['class'];
                    $verified = null;
                    $imp = false;
                    if (!empty($entry['verify']) && $ip !== '') {
                        $verified = ($this->rdns)($ip, $entry['verify']);
                        if ($verified === false) {
                            $class = 'automation'; // claimed a search engine, failed rDNS.
                            $imp = true;
                        }
                    }
                    return ['class' => $class, 'name' => $entry['name'], 'verified' => $verified, 'impersonation' => $imp];
                }
            }
        }

        if ($uaL === '') {
            return ['class' => 'automation', 'name' => 'Empty User-Agent', 'verified' => null, 'impersonation' => false];
        }

        // Generic bot words in an unknown UA.
        if (preg_match('/\b(bot|crawler|spider|scraper|fetcher|crawl|agent|archiver|indexer)\b/i', $uaL)) {
            return ['class' => 'bot', 'name' => self::shortName($ua), 'verified' => null, 'impersonation' => false];
        }

        // Browser-shaped UA.
        $browser = str_contains($uaL, 'mozilla/') && preg_match('/(chrome|safari|firefox|edg|opera|opr|msie|trident|samsungbrowser|ucbrowser)/', $uaL);
        if ($browser) {
            $accept = isset($headers['accept']) ? strtolower($headers['accept']) : '';
            // A real navigation sends text/html; API-ish Accept with a browser UA is a tool.
            if ($accept !== '' && !str_contains($accept, 'text/html') && !str_contains($accept, '*/*') && !str_contains($accept, 'image/')) {
                return ['class' => 'automation', 'name' => 'Browser-UA tool', 'verified' => null, 'impersonation' => false];
            }
            return ['class' => 'human', 'name' => '', 'verified' => null, 'impersonation' => false];
        }

        return ['class' => 'bot', 'name' => self::shortName($ua), 'verified' => null, 'impersonation' => false];
    }

    /** First token of an unknown UA, for the bot-name column. */
    public static function shortName(string $ua): string
    {
        $ua = trim($ua);
        if (preg_match('/^([A-Za-z0-9_.\-]+)/', $ua, $m)) {
            return substr($m[1], 0, 60);
        }
        return substr($ua, 0, 60);
    }

    /**
     * Merge a pulled agent feed into a catalog: new names appended, known names get extra match
     * strings (and verify suffixes when supplied).
     *
     * @param list<array<string,mixed>> $catalog
     * @param list<array<string,mixed>> $agents
     * @return list<array<string,mixed>>
     */
    public static function mergeCatalog(array $catalog, array $agents): array
    {
        $by = [];
        foreach ($catalog as $i => $e) {
            $by[$e['name']] = $i;
        }
        foreach ($agents as $a) {
            if (isset($by[$a['name']])) {
                $i = $by[$a['name']];
                $catalog[$i]['match'] = array_values(array_unique(array_merge($catalog[$i]['match'], $a['match'])));
                if (!empty($a['verify'])) {
                    $catalog[$i]['verify'] = $a['verify'];
                }
            } else {
                $catalog[] = $a;
            }
        }
        return $catalog;
    }

    /**
     * Reverse + forward DNS check, cached per IP for a day. true/false, or null when DNS is
     * unavailable (then the claim is taken at face value).
     *
     * @param list<string> $suffixes
     */
    public function verifyRdns(string $ip, array $suffixes): ?bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $key = 'ppc_rdns_' . md5($ip . '|' . implode(',', $suffixes));
        if ($this->cache) {
            $cached = $this->cache->get($key);
            if ($cached === 'yes' || $cached === 'no') {
                return $cached === 'yes';
            }
        }
        $result = null;
        $host = @gethostbyaddr($ip);
        if ($host && $host !== $ip) {
            $hostL = strtolower(rtrim($host, '.'));
            $ok = false;
            foreach ($suffixes as $s) {
                $s = strtolower($s);
                if (str_ends_with($hostL, $s)) {
                    $ok = true;
                    break;
                }
            }
            if ($ok) {
                $forward = @gethostbynamel($hostL);
                $ok = is_array($forward) && in_array($ip, $forward, true);
                if (!$ok && str_contains($ip, ':')) {
                    $records = @dns_get_record($hostL, DNS_AAAA);
                    if (is_array($records)) {
                        foreach ($records as $r) {
                            if (isset($r['ipv6']) && inet_pton($r['ipv6']) === inet_pton($ip)) {
                                $ok = true;
                                break;
                            }
                        }
                    }
                }
            }
            $result = $ok;
        } elseif ($host === $ip || $host === false) {
            $result = false; // no PTR at all: not a search engine.
        }
        $this->cache?->set($key, $result === null ? '' : ($result ? 'yes' : 'no'), 86400);
        return $result;
    }
}
