<?php

namespace Modules\Sviat\Redis;

use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class StubRedisCacheService extends RedisCacheService
{
    public array $bumps = [];

    /** Як у справжньому сервісі: повтори згортаються. Вимикати лише свідомо. */
    public bool $collapseBumpOnce = true;

    /** Значення, яке віддає get() — імітація HIT. null = MISS. */
    public $cachedValue = null;

    /** Ключі, збережені через set(): key => [value, ttl]. */
    public array $stored = [];

    public function __construct()
    {
        // Skip parent constructor — we only record bumps.
    }

    public function isEnabled(): bool { return true; }

    public function bump(string $tag): void
    {
        $this->bumps[] = $tag;
    }

    public function bumpOnce(string $tag): void
    {
        if ($this->collapseBumpOnce && in_array($tag, $this->bumps, true)) {
            return;
        }
        $this->bumps[] = $tag;
    }

    public function makeVersionedKey(string $name, array $tags, array $args = []): string
    {
        return $name . '|' . implode(',', $tags) . '|' . md5(serialize($args));
    }

    public function get(string $key)
    {
        return $this->cachedValue;
    }

    public function set(string $key, $value, ?int $ttl = null): void
    {
        $this->stored[$key] = [$value, $ttl];
    }

    public function getHelperTtl(string $helperKey): ?int
    {
        return null;
    }
}
