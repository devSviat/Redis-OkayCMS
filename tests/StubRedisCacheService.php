<?php

namespace Modules\Sviat\Redis;

use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class StubRedisCacheService extends RedisCacheService
{
    public array $bumps = [];

    public function __construct()
    {
        // Skip parent constructor — we only record bumps.
    }

    public function isEnabled(): bool { return true; }

    public function bump(string $tag): void
    {
        $this->bumps[] = $tag;
    }
}
