<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl;

/**
 * What the gate decided. If $respond is true, send status/headers/body and stop.
 * Otherwise let the request through and add $passHeaders to the response.
 */
final class GateResult
{
    /**
     * @param array<string,string> $headers
     * @param array<string,string> $passHeaders
     */
    private function __construct(
        public bool $respond,
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
        public array $passHeaders = []
    ) {
    }

    /** @param array<string,string> $passHeaders */
    public static function pass(array $passHeaders = []): self
    {
        return new self(false, 200, [], '', $passHeaders);
    }

    /** @param array<string,string> $headers */
    public static function respond(int $status, array $headers, string $body): self
    {
        return new self(true, $status, $headers, $body);
    }
}
