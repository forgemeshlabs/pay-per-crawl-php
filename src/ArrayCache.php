<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl;

use ForgeMesh\PayPerCrawl\Contracts\CacheInterface;

/** In-process cache: tests, CLI, or a single-process dev server. Not shared between requests. */
final class ArrayCache implements CacheInterface
{
    /** @var array<string,array{0:mixed,1:int}> */
    private array $items = [];

    public function get(string $key): mixed
    {
        if (!isset($this->items[$key])) {
            return null;
        }
        if ($this->items[$key][1] < time()) {
            unset($this->items[$key]);
            return null;
        }
        return $this->items[$key][0];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->items[$key] = [$value, time() + $ttlSeconds];
    }

    public function add(string $key, mixed $value, int $ttlSeconds): bool
    {
        if ($this->get($key) !== null) {
            return false;
        }
        $this->set($key, $value, $ttlSeconds);
        return true;
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}
