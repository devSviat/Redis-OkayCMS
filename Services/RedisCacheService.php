<?php

namespace Okay\Modules\Sviat\Redis\Services;

use Okay\Core\Settings;

class RedisCacheService
{
    private Settings $settings;
    private $client = null;
    private bool $initialized = false;
    private ?string $lastError = null;
    private ?array $context = null;

    // In-request memo to avoid duplicate Redis GET.
    private array $helperTtlCache = [];
    private array $canCacheMemo = [];
    private array $variantsVersionCache = []; // productId => int
    private ?int $globalVariantsVersionCache = null;
    private array $getMemo = []; // key => [bool $hasValue, mixed $value]

    // phpredis auth() signature cache.
    private static ?bool $authSupportsTwoArgs = null;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    private function loadConfig(): array
    {
        $password = trim((string) ($this->settings->get('sviat__redis__password') ?? ''));
        $password = $password !== '' ? $password : null;
        $username = trim((string) ($this->settings->get('sviat__redis__username') ?? ''));
        $username = $username !== '' ? $username : null;

        return [
            'enabled'  => (bool) $this->settings->get('sviat__redis__enabled'),
            'host'     => $this->settings->get('sviat__redis__host') ?: '127.0.0.1',
            'port'     => (int) ($this->settings->get('sviat__redis__port') ?: 6379),
            'db'       => (int) ($this->settings->get('sviat__redis__db') ?: 0),
            'username' => $username,
            'auth'     => $password,
            'prefix'   => $this->settings->get('sviat__redis__prefix') ?: 'okay:',
            'ttl'      => (int) ($this->settings->get('sviat__redis__default_ttl') ?: 600),
        ];
    }

    /**
     * Секрет HMAC для підпису серіалізованих helper-кешів (set/get/mGet).
     * Порожньо = старий формат без підпису (сумісність). Непорожньо = відхиляються сторонні записи в Redis.
     */
    private function getCacheHmacSecret(): string
    {
        $raw = $this->settings->get('sviat__redis__cache_hmac_secret');
        if (!is_string($raw)) {
            return '';
        }
        $s = trim($raw);

        return $s;
    }

    /** Макс. розмір серіалізованого тіла (захист від DoS при підробленому заголовку довжини). */
    private const SIGNED_PAYLOAD_MAX_BYTES = 16777216;

    /**
     * Обгортка: v1 + 4 байти довжини (N) + 32 байти HMAC-SHA256 + serialized.
     */
    private function wrapSerializedPayload(string $serialized): string
    {
        $secret = $this->getCacheHmacSecret();
        if ($secret === '') {
            return $serialized;
        }

        $sig = hash_hmac('sha256', $serialized, $secret, true);

        return 'v1' . pack('N', strlen($serialized)) . $sig . $serialized;
    }

    /**
     * @return string|null серіалізовані дані або null (пошкоджено / підробка / не той формат при увімкненому HMAC)
     */
    private function unwrapSerializedPayload(string $data): ?string
    {
        $secret = $this->getCacheHmacSecret();
        if ($secret === '') {
            return $data;
        }

        $header = 2 + 4 + 32;
        if (strlen($data) < $header) {
            $this->lastError = 'Redis cache: truncated signed payload';

            return null;
        }
        if (substr($data, 0, 2) !== 'v1') {
            $this->lastError = 'Redis cache: unsigned or legacy payload rejected (HMAC увімкнено)';

            return null;
        }

        $u = unpack('N', substr($data, 2, 4));
        $len = (int) ($u[1] ?? 0);
        if ($len < 1 || $len > self::SIGNED_PAYLOAD_MAX_BYTES) {
            $this->lastError = 'Redis cache: invalid payload length';

            return null;
        }
        if (strlen($data) !== $header + $len) {
            $this->lastError = 'Redis cache: payload size mismatch';

            return null;
        }

        $sig = substr($data, 6, 32);
        $serialized = substr($data, $header);
        $expected = hash_hmac('sha256', $serialized, $secret, true);
        if (!hash_equals($expected, $sig)) {
            $this->lastError = 'Redis cache: HMAC verification failed';

            return null;
        }

        return $serialized;
    }

    public function isEnabled(): bool
    {
        $config = $this->loadConfig();
        return $config['enabled'] && class_exists('\\Redis');
    }

    /** @return object|null Memoized phpredis client, or null if unavailable. */
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

    /** Authenticate with password or ACL credentials. */
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
            // Ignore and fallback to raw AUTH.
        }

        if (!method_exists($redis, 'rawCommand')) {
            $this->lastError = 'Для Redis ACL потрібен phpredis з auth(username, password) або підтримкою rawCommand';

            return false;
        }

        $result = $redis->rawCommand('AUTH', $user, $passStr);

        return $result !== false;
    }

    /**
     * Create Redis client by current settings.
     *
     * @param bool $applyKeyPrefix Enable OPT_PREFIX for client keys.
     * @return object|null Redis client or null on failure.
     */
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
            if ($config['db'] > 0) {
                if (!$redis->select($config['db'])) {
                    $this->lastError = 'Redis select DB failed';
                    return null;
                }
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

    /** Normalize different phpredis PING responses. */
    private function isPingSuccessful($response): bool
    {
        if ($response === true || $response === 1) {
            return true;
        }
        if (is_string($response)) {
            $s = strtoupper(trim($response));

            return $s === 'PONG' || $s === '+PONG';
        }

        return false;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /** Check connection. Can bypass "enabled" flag for admin test. */
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
            $ok = $this->isPingSuccessful($client->ping());
            if (!$ok) {
                $this->lastError = 'Unexpected PING response from Redis';
            }

            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        } finally {
            try {
                $client->close();
            } catch (\Throwable $e) {
                // Ignore close errors.
            }
        }
    }

    public function makeKey(string $name, array $args = []): string
    {
        $ctx = $this->getContext();
        return 'helpers:' . $name
            . ':l' . ($ctx['lang'] ?? '0')
            . ':c' . ($ctx['currency'] ?? '0')
            . ':g' . ($ctx['group'] ?? '0')
            . ':' . md5(serialize($args));
    }

    public function canCache(string $name): bool
    {
        if (isset($this->canCacheMemo[$name])) {
            return $this->canCacheMemo[$name];
        }

        if (!$this->isEnabled()) {
            return $this->canCacheMemo[$name] = false;
        }

        // Do not cache admin requests.
        $uri = \Okay\Core\Request::getRequestUri();
        if ($uri !== '' && (str_starts_with($uri, 'backend') || str_starts_with($uri, '/backend'))) {
            return false;
        }
        if (!empty($_GET['controller']) && is_string($_GET['controller'])) {
            if (str_starts_with($_GET['controller'], 'Sviat.') || str_contains($_GET['controller'], 'Admin')) {
                // Most admin controllers match this pattern.
                return false;
            }
        }

        // Payment methods may contain gateway secrets.
        if (strtolower($name) === 'main_payment_methods') {
            return false;
        }

        // Explicit deny list.
        $deny = [
            'orders',
            'users',
            'cart',
            'admin',
            'stock',
            'warehouse',
            'inventory',
        ];
        $lower = strtolower($name);
        foreach ($deny as $word) {
            if (str_contains($lower, $word)) {
                return $this->canCacheMemo[$name] = false;
            }
        }

        return $this->canCacheMemo[$name] = true;
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
                $languages = $sl->getService(\Okay\Core\Languages::class);
                $langId = $languages->getLangId();
            }
        } catch (\Throwable $e) {
            $langId = null;
        }

        $currencyId = $_SESSION['currency_id'] ?? null;
        $currencyId = $currencyId !== null ? (int) $currencyId : null;

        $groupId = 0;
        try {
            if (!empty($_SESSION['user_id'])) {
                $sl = \Okay\Core\ServiceLocator::getInstance();
                if ($sl->hasService(\Okay\Core\EntityFactory::class)) {
                    $ef = $sl->getService(\Okay\Core\EntityFactory::class);
                    /** @var \Okay\Entities\UsersEntity $usersEntity */
                    $usersEntity = $ef->get(\Okay\Entities\UsersEntity::class);
                    $user = $usersEntity->get((int) $_SESSION['user_id']);
                    if (!empty($user) && isset($user->group_id)) {
                        $groupId = (int) $user->group_id;
                    }
                }
            }
        } catch (\Throwable $e) {
            $groupId = 0;
        }

        $this->context = [
            'lang' => $langId !== null ? (int) $langId : 0,
            'currency' => $currencyId !== null ? (int) $currencyId : 0,
            'group' => (int) $groupId,
        ];

        return $this->context;
    }

    public function get(string $key)
    {
        if (array_key_exists($key, $this->getMemo)) {
            [$hasValue, $value] = $this->getMemo[$key];
            return $hasValue ? $value : null;
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
            $serialized = $this->unwrapSerializedPayload((string) $data);
            if ($serialized === null) {
                $this->getMemo[$key] = [false, null];
                return null;
            }
            $value = $this->safeUnserialize($serialized);
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

    /**
     * Batch GET in one roundtrip.
     *
     * @param string[] $keys
     * @return array<string, mixed|null> Map key => value|null.
     */
    public function mGet(array $keys): array
    {
        $keys = array_values(array_filter(array_map('strval', $keys), static function ($k) {
            return $k !== '';
        }));
        if ($keys === []) {
            return [];
        }

        $result = [];

        // Use in-request memo first.
        $needFetch = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $this->getMemo)) {
                [$hasValue, $value] = $this->getMemo[$k];
                $result[$k] = $hasValue ? $value : null;
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
                if (!is_string($data)) {
                    $data = (string) $data;
                }
                $serialized = $this->unwrapSerializedPayload((string) $data);
                if ($serialized === null) {
                    $this->getMemo[$k] = [false, null];
                    $result[$k] = null;
                    continue;
                }
                $value = $this->safeUnserialize($serialized);
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

    /** Safe unserialize with allowed class whitelist. */
    private function safeUnserialize(string $data)
    {
        // Convert warnings to exceptions on invalid payload.
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
            $data = $this->wrapSerializedPayload(serialize($value));
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

    /**
     * Версія кешу variants для конкретного товару.
     * Інвалідація: bumpProductVariantsVersion($productId)
     */
    public function getProductVariantsVersion(int $productId): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        if (array_key_exists($productId, $this->variantsVersionCache)) {
            return (int) $this->variantsVersionCache[$productId];
        }

        $client = $this->initClient();
        if (!$client) {
            return 0;
        }

        $key = 'helpers:pvver:' . $productId;
        try {
            $val = $client->get($key);
            if ($val === false || $val === null || $val === '') {
                return 0;
            }
            $result = (int) $val;
            $this->variantsVersionCache[$productId] = $result;
            return $result;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return 0;
        }
    }

    public function bumpProductVariantsVersion(int $productId): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $client = $this->initClient();
        if (!$client) {
            return;
        }

        $key = 'helpers:pvver:' . $productId;
        try {
            // incr creates key if missing.
            $client->incr($key);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        // Drop local memo for this product.
        unset($this->variantsVersionCache[$productId]);
    }

    /** Global invalidation for variants cache. */
    public function bumpGlobalVariantsVersion(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $client = $this->initClient();
        if (!$client) {
            return;
        }

        $key = 'helpers:pvver:global';
        try {
            $client->incr($key);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        // Drop local memo.
        $this->globalVariantsVersionCache = null;
    }

    public function getGlobalVariantsVersion(): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        if ($this->globalVariantsVersionCache !== null) {
            return $this->globalVariantsVersionCache;
        }

        $client = $this->initClient();
        if (!$client) {
            return 0;
        }

        $key = 'helpers:pvver:global';
        try {
            $val = $client->get($key);
            if ($val === false || $val === null || $val === '') {
                return 0;
            }
            $result = (int) $val;
            $this->globalVariantsVersionCache = $result;
            return $result;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return 0;
        }
    }

    /** Clear all keys in current Redis DB. */
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
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        } finally {
            try {
                $client->close();
            } catch (\Throwable $e) {
                // Ignore close errors.
            }
        }
    }

    public function getStats(): array
    {
        $client = $this->initClient();
        if (!$client) {
            return [
                'enabled' => false,
                'connected' => false,
                'error' => $this->lastError,
            ];
        }

        try {
            $info = $client->info();
            $dbSize = $client->dbSize();

            return [
                'enabled'   => true,
                'connected' => true,
                'db_size'   => $dbSize,
                'used_memory' => isset($info['used_memory_human']) ? $info['used_memory_human'] : ($info['used_memory'] ?? null),
                'raw_info'  => $info,
            ];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [
                'enabled' => true,
                'connected' => false,
                'error' => $this->lastError,
            ];
        }
    }

    public function getHelperTtl(string $helperKey): ?int
    {
        if (array_key_exists($helperKey, $this->helperTtlCache)) {
            return $this->helperTtlCache[$helperKey];
        }

        $value = $this->settings->get('sviat__redis__ttl__' . $helperKey);
        $result = $value !== null && $value !== '' ? (int) $value : null;
        $this->helperTtlCache[$helperKey] = $result;

        return $result;
    }
}

