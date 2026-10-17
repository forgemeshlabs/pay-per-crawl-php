<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl\Contracts;

/**
 * Where the gate writes one row per classified request. Row keys:
 * ts (UTC "Y-m-d H:i:s"), ua_class, bot_name, verified (?bool), method, path, status
 * (passed|monitor|unpaid|rejected|settle_failed|paid|blocked), rail, amount_units (int),
 * tx_hash, payer, reason, ip_hash, ua.
 */
interface HitStoreInterface
{
    /** @param array<string,mixed> $row */
    public function record(array $row): int;

    /**
     * Per-bot rollup for one UTC day (Y-m-d). Row keys: bot_name, ua_class, hits, paid, unpaid,
     * rejected, monitor, revenue_units, would_units, sources.
     * @return list<array<string,mixed>>
     */
    public function dailyRollup(string $day): array;

    /**
     * Paid rows with id greater than $afterId, ascending. Row keys: id, ts, bot_name, ua_class,
     * path, rail, amount_units, tx_hash, payer.
     * @return list<array<string,mixed>>
     */
    public function settlementsAfter(int $afterId, int $limit): array;
}
