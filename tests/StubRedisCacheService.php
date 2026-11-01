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

    public bool $enabled = true;

    public function isEnabled(): bool { return $this->enabled; }

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
        $this->lastKey = $key;
        return $this->cachedValue;
    }

    public function set(string $key, $value, ?int $ttl = null): void
    {
        $this->stored[$key] = [$value, $ttl];
    }

    /** @var array<string, int> helperKey => TTL з налаштувань */
    public array $helperTtls = [];

    /** Ключ, за яким востаннє читали кеш. */
    public ?string $lastKey = null;

    public function getHelperTtl(string $helperKey): ?int
    {
        return $this->helperTtls[$helperKey] ?? null;
    }

    /**
     * Конструктор батька пропущено, тож справжній remember() впав би на
     * loadConfig(). Тут той самий контракт, але без Redis: cachedValue — влучання.
     */
    public function remember(
        string $name,
        array $tags,
        array $args,
        callable $producer,
        ?int $ttl = null,
        ?callable $onHit = null,
        ?callable $worthStoring = null
    ) {
        if ($this->cachedValue !== null) {
            return $onHit === null ? $this->cachedValue : $onHit($this->cachedValue);
        }

        $value = $producer();
        if ($worthStoring === null || $worthStoring($value)) {
            $this->stored[$this->makeVersionedKey($name, $tags, $args)] = [$value, $ttl];
        }

        return $value;
    }
}
