<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl\Contracts;

/** Minimal key/value cache. Values are scalars or arrays. */
interface CacheInterface
{
    /** @return mixed|null null when missing or expired */
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): void;

    /** Store only if the key is absent. Must be atomic where the backend allows it. */
    public function add(string $key, mixed $value, int $ttlSeconds): bool;

    public function delete(string $key): void;
}
