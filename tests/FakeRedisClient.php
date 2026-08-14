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

    public function set($key, $value): bool
    {
        $this->calls[] = ['set', $key, $value];
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

    public function flushDB(): bool { $this->store = []; $this->ttls = []; return true; }
}
