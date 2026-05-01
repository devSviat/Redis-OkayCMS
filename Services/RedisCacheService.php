<?php

namespace Okay\Modules\Sviat\Redis\Services;

use Okay\Core\Settings;

class RedisCacheService
{
    private const VERSION_KEY_PREFIX = 'helpers:ver:';

    private Settings $settings;
    private $client = null;
    private bool $initialized = false;
    private ?string $lastError = null;
    private ?array $context = null;

    private array $helperTtlCache = [];
    private array $versionMemo = [];
    private array $getMemo = [];

    private static ?bool $authSupportsTwoArgs = null;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    private function loadConfig(): array
    {
        $password = trim((string) ($this->settings->get('sviat__redis__password') ?? ''));
        $username = trim((string) ($this->settings->get('sviat__redis__username') ?? ''));

        return [
            'enabled'  => (bool) $this->settings->get('sviat__redis__enabled'),
            'host'     => $this->settings->get('sviat__redis__host') ?: '127.0.0.1',
            'port'     => (int) ($this->settings->get('sviat__redis__port') ?: 6379),
            'db'       => (int) ($this->settings->get('sviat__redis__db') ?: 0),
            'username' => $username !== '' ? $username : null,
            'auth'     => $password !== '' ? $password : null,
            'prefix'   => $this->settings->get('sviat__redis__prefix') ?: 'okay:',
            'ttl'      => (int) ($this->settings->get('sviat__redis__default_ttl') ?: 600),
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('sviat__redis__enabled') && class_exists('\\Redis');
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private function initClient(): ?object
    {
        if ($this->initialized) {
            return $this->client;
        }
        $this->initialized = true;
        if (!$this->isEnabled()) {
            $this->lastError = 'Redis disabled or extension not installed';
            return null;
        }
        $this->client = $this->connectBare(true);
        return $this->client;
    }

    private function authenticateRedis(object $redis, ?string $username, ?string $password): bool
    {
        $user = $username !== null && trim($username) !== '' ? trim($username) : null;
        $pass = $password !== null && trim($password) !== '' ? $password : null;

        if ($user === null && $pass === null) {
            return true;
        }
        if ($user === null) {
            return (bool) $redis->auth($pass);
        }

        $passStr = $pass ?? '';
        try {
            if (self::$authSupportsTwoArgs === null) {
                $ref = new \ReflectionMethod($redis, 'auth');
                self::$authSupportsTwoArgs = $ref->getNumberOfParameters() >= 2;
            }
            if (self::$authSupportsTwoArgs) {
                return (bool) $redis->auth($user, $passStr);
            }
        } catch (\ReflectionException $e) {
            // fallthrough
        }
        if (!method_exists($redis, 'rawCommand')) {
            $this->lastError = 'Redis ACL requires phpredis with auth(username, password) or rawCommand support';
            return false;
        }
        return $redis->rawCommand('AUTH', $user, $passStr) !== false;
    }

    private function connectBare(bool $applyKeyPrefix = true)
    {
        $config = $this->loadConfig();
        try {
            if (!class_exists('\\Redis')) {
                $this->lastError = 'Redis extension not loaded';
                return null;
            }
            $redisClass = 'Redis';
            $redis = new $redisClass();
            if (!$redis->connect($config['host'], $config['port'], 1.0)) {
                $this->lastError = 'Unable to connect to Redis';
                return null;
            }
            if (!$this->authenticateRedis($redis, $config['username'], $config['auth'])) {
                if ($this->lastError === null) {
                    $this->lastError = 'Redis auth failed';
                }
                return null;
            }
            if ($config['db'] > 0 && !$redis->select($config['db'])) {
                $this->lastError = 'Redis select DB failed';
                return null;
            }
            if ($applyKeyPrefix && $config['prefix']) {
                $optPrefix = defined('Redis::OPT_PREFIX') ? constant('Redis::OPT_PREFIX') : null;
                if ($optPrefix !== null) {
                    $redis->setOption($optPrefix, $config['prefix']);
                }
            }
            return $redis;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    public function testConnection(bool $allowWhenDisabled = false): bool
    {
        $this->lastError = null;
        if (!$allowWhenDisabled && !$this->isEnabled()) {
            $this->lastError = 'Redis disabled or extension not installed';
            return false;
        }
        if (!class_exists('\\Redis')) {
            $this->lastError = 'Redis extension not loaded';
            return false;
        }
        $client = $this->connectBare(true);
        if (!$client) {
            return false;
        }
        try {
            $resp = $client->ping();
            $ok = $resp === true || $resp === 1
                || (is_string($resp) && in_array(strtoupper(trim($resp)), ['PONG', '+PONG'], true));
            if (!$ok) {
                $this->lastError = 'Unexpected PING response from Redis';
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        } finally {
            try { $client->close(); } catch (\Throwable $e) {}
        }
    }

    public function flushAll(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $client = $this->connectBare(false);
        if (!$client) {
            return;
        }
        try {
            $client->flushDB();
            $this->versionMemo = [];
            $this->getMemo = [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        } finally {
            try { $client->close(); } catch (\Throwable $e) {}
        }
    }

    public function getStats(): array
    {
        $client = $this->initClient();
        if (!$client) {
            return ['enabled' => false, 'connected' => false, 'error' => $this->lastError];
        }
        try {
            $info = $client->info();
            return [
                'enabled'   => true,
                'connected' => true,
                'db_size'   => $client->dbSize(),
                'used_memory' => $info['used_memory_human'] ?? ($info['used_memory'] ?? null),
                'raw_info'  => $info,
            ];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return ['enabled' => true, 'connected' => false, 'error' => $this->lastError];
        }
    }

    public function getHelperTtl(string $helperKey): ?int
    {
        if (\array_key_exists($helperKey, $this->helperTtlCache)) {
            return $this->helperTtlCache[$helperKey];
        }
        $value = $this->settings->get('sviat__redis__ttl__' . $helperKey);
        $result = $value !== null && $value !== '' ? (int) $value : null;
        $this->helperTtlCache[$helperKey] = $result;
        return $result;
    }

    private function getContext(): array
    {
        if ($this->context !== null) {
            return $this->context;
        }
        $langId = null;
        try {
            $sl = \Okay\Core\ServiceLocator::getInstance();
            if ($sl->hasService(\Okay\Core\Languages::class)) {
                $langId = (int) $sl->getService(\Okay\Core\Languages::class)->getLangId();
            }
        } catch (\Throwable $e) {}

        $currencyId = isset($_SESSION['currency_id']) ? (int) $_SESSION['currency_id'] : 0;
        $groupId = 0;
        try {
            if (!empty($_SESSION['user_id'])) {
                $sl = \Okay\Core\ServiceLocator::getInstance();
                if ($sl->hasService(\Okay\Core\EntityFactory::class)) {
                    $ef = $sl->getService(\Okay\Core\EntityFactory::class);
                    $usersEntity = $ef->get(\Okay\Entities\UsersEntity::class);
                    $user = $usersEntity->get((int) $_SESSION['user_id']);
                    if (!empty($user) && isset($user->group_id)) {
                        $groupId = (int) $user->group_id;
                    }
                }
            }
        } catch (\Throwable $e) {}

        $this->context = ['lang' => $langId ?? 0, 'currency' => $currencyId, 'group' => $groupId];
        return $this->context;
    }

    public function makeVersionedKey(string $name, array $tags, array $args = []): string
    {
        $ctx = $this->getContext();
        $tagSegment = '';
        if ($tags !== []) {
            $versions = $this->versions($tags);
            $parts = [];
            foreach ($tags as $tag) {
                $parts[] = ':' . self::tagSegmentLabel($tag) . ($versions[$tag] ?? 0);
            }
            $tagSegment = implode('', $parts);
        }
        return 'helpers:' . $name
            . ':l' . ($ctx['lang'] ?? 0)
            . ':c' . ($ctx['currency'] ?? 0)
            . ':g' . ($ctx['group'] ?? 0)
            . $tagSegment
            . ':' . md5(serialize($args));
    }

    private static function tagSegmentLabel(string $tag): string
    {
        $colon = strpos($tag, ':');
        $head = $colon === false ? $tag : substr($tag, 0, $colon);
        $tail = $colon === false ? '' : substr($tag, $colon + 1);
        $shortHead = strlen($head) >= 2 ? $head[0] . $head[1] : $head;
        if ($tail === 'global' || $tail === '') {
            return $shortHead;
        }
        return $shortHead . preg_replace('/[^a-zA-Z0-9]/', '', $tail);
    }

    public function version(string $tag): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }
        if (\array_key_exists($tag, $this->versionMemo)) {
            return $this->versionMemo[$tag];
        }
        $client = $this->initClient();
        if (!$client) {
            return $this->versionMemo[$tag] = 0;
        }
        try {
            $val = $client->get(self::VERSION_KEY_PREFIX . $tag);
            $result = ($val === false || $val === null || $val === '') ? 0 : (int) $val;
            return $this->versionMemo[$tag] = $result;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return $this->versionMemo[$tag] = 0;
        }
    }

    public function versions(array $tags): array
    {
        $tags = array_values(array_unique(array_filter(array_map('strval', $tags))));
        if ($tags === []) {
            return [];
        }
        $result = [];
        $missing = [];
        foreach ($tags as $tag) {
            if (\array_key_exists($tag, $this->versionMemo)) {
                $result[$tag] = $this->versionMemo[$tag];
            } else {
                $missing[] = $tag;
            }
        }
        if ($missing === [] || !$this->isEnabled()) {
            foreach ($missing as $tag) {
                $result[$tag] = $this->versionMemo[$tag] = 0;
            }
            return $result;
        }
        $client = $this->initClient();
        if (!$client) {
            foreach ($missing as $tag) {
                $result[$tag] = $this->versionMemo[$tag] = 0;
            }
            return $result;
        }
        try {
            $keys = array_map(fn($t) => self::VERSION_KEY_PREFIX . $t, $missing);
            $raw = $client->mGet($keys);
            foreach ($missing as $i => $tag) {
                $val = $raw[$i] ?? false;
                $n = ($val === false || $val === null || $val === '') ? 0 : (int) $val;
                $result[$tag] = $this->versionMemo[$tag] = $n;
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            foreach ($missing as $tag) {
                $result[$tag] = $this->versionMemo[$tag] = 0;
            }
        }
        return $result;
    }

    public function bump(string $tag): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $client = $this->initClient();
        if (!$client) {
            return;
        }
        try {
            $client->incr(self::VERSION_KEY_PREFIX . $tag);
            unset($this->versionMemo[$tag]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }
    }

    public function get(string $key)
    {
        if (\array_key_exists($key, $this->getMemo)) {
            [$has, $val] = $this->getMemo[$key];
            return $has ? $val : null;
        }
        $client = $this->initClient();
        if (!$client) {
            $this->getMemo[$key] = [false, null];
            return null;
        }
        try {
            $data = $client->get($key);
            if ($data === false || $data === null) {
                $this->getMemo[$key] = [false, null];
                return null;
            }
            $value = $this->safeUnserialize((string) $data);
            if ($value === null) {
                $this->getMemo[$key] = [false, null];
                return null;
            }
            $this->getMemo[$key] = [true, $value];
            return $value;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->getMemo[$key] = [false, null];
            return null;
        }
    }

    public function mGet(array $keys): array
    {
        $keys = array_values(array_filter(array_map('strval', $keys), static fn($k) => $k !== ''));
        if ($keys === []) {
            return [];
        }
        $result = [];
        $needFetch = [];
        foreach ($keys as $k) {
            if (\array_key_exists($k, $this->getMemo)) {
                [$has, $val] = $this->getMemo[$k];
                $result[$k] = $has ? $val : null;
            } else {
                $needFetch[] = $k;
            }
        }
        if ($needFetch === []) {
            return $result;
        }
        $client = $this->initClient();
        if (!$client) {
            foreach ($needFetch as $k) {
                $this->getMemo[$k] = [false, null];
                $result[$k] = null;
            }
            return $result;
        }
        try {
            $raw = $client->mGet($needFetch);
            if (!is_array($raw)) {
                $raw = [];
            }
            foreach ($needFetch as $i => $k) {
                $data = $raw[$i] ?? null;
                if ($data === false || $data === null) {
                    $this->getMemo[$k] = [false, null];
                    $result[$k] = null;
                    continue;
                }
                $value = $this->safeUnserialize((string) $data);
                if ($value === null) {
                    $this->getMemo[$k] = [false, null];
                    $result[$k] = null;
                    continue;
                }
                $this->getMemo[$k] = [true, $value];
                $result[$k] = $value;
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            foreach ($needFetch as $k) {
                $this->getMemo[$k] = [false, null];
                $result[$k] = null;
            }
        }
        return $result;
    }

    private function safeUnserialize(string $data)
    {
        set_error_handler(function ($severity, $message) {
            throw new \RuntimeException($message, (int) $severity);
        });
        try {
            return unserialize($data, ['allowed_classes' => ['stdClass']]);
        } catch (\Throwable $e) {
            $this->lastError = 'Unserialize failed: ' . $e->getMessage();
            return null;
        } finally {
            restore_error_handler();
        }
    }

    public function set(string $key, $value, ?int $ttl = null): void
    {
        $client = $this->initClient();
        if (!$client) {
            return;
        }
        $config = $this->loadConfig();
        $ttl = $ttl ?? $config['ttl'];
        try {
            $data = serialize($value);
            if ($ttl > 0) {
                $client->setex($key, $ttl, $data);
            } else {
                $client->set($key, $data);
            }
            $this->getMemo[$key] = [true, $value];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }
    }
}
