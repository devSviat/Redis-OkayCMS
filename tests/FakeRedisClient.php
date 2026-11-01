<?php

namespace Modules\Sviat\Redis;

class FakeRedisClient
{
    public array $store = [];
    public array $ttls  = [];
    public array $calls = [];
    public bool $connected = true;

    public function connect($host, $port, $timeout = 0.0): bool { $this->calls[] = ['connect', $host, $port]; return $this->connected; }
    public function auth($a, $b = null): bool { $this->calls[] = ['auth']; return true; }
    public function select($db): bool { $this->calls[] = ['select', $db]; return true; }
    public function setOption($opt, $val): bool { $this->calls[] = ['setOption', $opt, $val]; return true; }
    public function close(): bool { $this->calls[] = ['close']; return true; }
    public function ping() { $this->calls[] = ['ping']; return '+PONG'; }
    public function info(): array { return ['used_memory_human' => '1M']; }
    public function dbSize(): int { return count($this->store); }

    public function get($key)
    {
        $this->calls[] = ['get', $key];
        return $this->store[$key] ?? false;
    }

    public function mGet(array $keys): array
    {
        $this->calls[] = ['mGet', $keys];
        $out = [];
        foreach ($keys as $k) {
            $out[] = $this->store[$k] ?? false;
        }
        return $out;
    }

    /** Третій аргумент — опції phpredis: ['nx', 'ex' => N]. NX тут справжній. */
    public function set($key, $value, $options = null): bool
    {
        $this->calls[] = ['set', $key, $value];
        if (is_array($options) && in_array('nx', $options, true) && array_key_exists($key, $this->store)) {
            return false;
        }
        $this->store[$key] = $value;
        return true;
    }

    public function setex($key, $ttl, $value): bool
    {
        $this->calls[] = ['setex', $key, $ttl, $value];
        $this->store[$key] = $value;
        $this->ttls[$key]  = $ttl;
        return true;
    }

    /** Скільки наступних incr мають кинути виняток (імітація таймауту). */
    public int $failIncrTimes = 0;

    public function incr($key)
    {
        $this->calls[] = ['incr', $key];
        if ($this->failIncrTimes > 0) {
            $this->failIncrTimes--;
            throw new \RuntimeException('read error on connection');
        }
        $this->store[$key] = (int) ($this->store[$key] ?? 0) + 1;
        return $this->store[$key];
    }

    public function del(...$keys): int
    {
        $deleted = 0;
        foreach ($keys as $key) {
            $this->calls[] = ['del', $key];
            if (array_key_exists($key, $this->foreign)) {
                unset($this->foreign[$key]);
                $deleted++;
                continue;
            }
            $logical = str_starts_with($key, $this->physicalPrefix)
                ? substr($key, strlen($this->physicalPrefix))
                : $key;
            if (array_key_exists($logical, $this->store)) {
                unset($this->store[$logical], $this->ttls[$logical]);
                $deleted++;
            }
        }
        return $deleted;
    }

    /** store тримає ключі без префікса: так їх бачить клієнт з OPT_PREFIX. SCAN віддає фізичні імена. */
    public string $physicalPrefix = 'okay:';

    /** Ключі інших сервісів у тій самій DB, із повними іменами. */
    public array $foreign = [];

    public int $scanPageSize = 1000;
    public int $failScanTimes = 0;

    public function scan(&$cursor, $pattern = null, $count = 0)
    {
        $this->calls[] = ['scan', $pattern];
        if ($this->failScanTimes > 0) {
            $this->failScanTimes--;
            throw new \RuntimeException('scan failed');
        }
        $physical = array_merge(
            array_map(fn ($k) => $this->physicalPrefix . $k, array_keys($this->store)),
            array_keys($this->foreign)
        );
        $matched = array_values(array_filter($physical, fn ($k) => fnmatch((string) $pattern, $k)));
        $page = array_slice($matched, 0, $this->scanPageSize);
        $cursor = count($matched) > count($page) ? 1 : 0;
        return $page;
    }

    public function eval($script, $args = [], $numKeys = 0)
    {
        $this->calls[] = ['eval', $args];
        [$key, $token] = $args;
        if (($this->store[$key] ?? null) !== $token) {
            return 0;
        }
        unset($this->store[$key]);
        return 1;
    }
}
