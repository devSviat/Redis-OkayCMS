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

    /**
     * Підлога TTL. Під maxmemory-policy volatile-lru виселяються лише ключі
     * з TTL, тож запис без нього зробив би кеш невиселюваним — і тиск пішов
     * би на лічильники версій, виселення яких «воскрешає» старі дані.
     */
    private const MIN_TTL = 60;

    /** Понад стільки тегів вектор версій згортається в хеш. */
    private const MAX_INLINE_TAGS = 4;

    /** Вікно схлопування повторних bump одного тега, секунди. */
    private const BUMP_COLLAPSE_WINDOW = 1.0;

    private array $helperTtlCache = [];
    private array $versionMemo = [];
    private array $getMemo = [];
    /** tag => час останнього успішного bump (unix float). */
    private array $bumpedTags = [];
    /** tag => true для bump, які схлопнуло вікно і які ще треба зробити. */
    private array $pendingBumps = [];
    private bool $pendingFlushRegistered = false;
    private int $reconnectsLeft = 2;

    private int $statHits = 0;
    private int $statMisses = 0;
    private int $statBumps = 0;
    private int $statRoundTrips = 0;
    private float $statSeconds = 0.0;

    private static ?bool $authSupportsTwoArgs = null;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Env має пріоритет над налаштуваннями в БД: інакше зміна REDIS_PASSWORD
     * у .env лишає сайт мовчки без кешу, поки хтось не відкриє адмінку.
     */
    private function loadConfig(): array
    {
        $password = self::env('REDIS_PASSWORD')
            ?? trim((string) ($this->settings->get('sviat__redis__password') ?? ''));
        $username = self::env('REDIS_USERNAME')
            ?? trim((string) ($this->settings->get('sviat__redis__username') ?? ''));

        $enabledEnv = self::env('REDIS_ENABLED');

        return [
            'enabled'  => $enabledEnv !== null
                ? filter_var($enabledEnv, FILTER_VALIDATE_BOOLEAN)
                : (bool) $this->settings->get('sviat__redis__enabled'),
            'host'     => self::env('REDIS_HOST') ?: ($this->settings->get('sviat__redis__host') ?: '127.0.0.1'),
            'port'     => (int) (self::env('REDIS_PORT') ?: ($this->settings->get('sviat__redis__port') ?: 6379)),
            'db'       => (int) (self::env('REDIS_DB') ?? ($this->settings->get('sviat__redis__db') ?: 0)),
            'username' => $username !== '' ? $username : null,
            'auth'     => $password !== '' ? $password : null,
            'prefix'   => self::env('REDIS_PREFIX') ?: ($this->settings->get('sviat__redis__prefix') ?: 'okay:'),
            'ttl'      => (int) ($this->settings->get('sviat__redis__default_ttl') ?: 600),
        ];
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    public function isEnabled(): bool
    {
        return $this->loadConfig()['enabled'] && class_exists('\\Redis');
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
        $this->client = $this->connectBare(true, true);
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

    /**
     * @param bool $persistent pconnect тримає з'єднання між запитами воркера.
     *        Непостійне потрібне тим шляхам, що роблять close() — закривати
     *        persistent-з'єднання не можна.
     */
    private function connectBare(bool $applyKeyPrefix = true, bool $persistent = false)
    {
        $config = $this->loadConfig();
        try {
            if (!class_exists('\\Redis')) {
                $this->lastError = 'Redis extension not loaded';
                return null;
            }
            $redisClass = 'Redis';
            $redis = new $redisClass();
            // Без method_exists(): pconnect є в phpredis завжди, і PHPStan
            // справедливо лається на перевірку, що ніколи не хибна.
            if ($persistent) {
                $persistentId = 'okay:' . $config['host'] . ':' . $config['port']
                    . ':' . $config['db'] . ':' . ($config['username'] ?? '');
                $connected = $redis->pconnect($config['host'], $config['port'], 0.5, $persistentId);
            } else {
                $connected = $redis->connect($config['host'], $config['port'], 1.0);
            }
            if (!$connected) {
                $this->lastError = 'Unable to connect to Redis';
                return null;
            }
            $optReadTimeout = defined('Redis::OPT_READ_TIMEOUT') ? constant('Redis::OPT_READ_TIMEOUT') : null;
            if ($optReadTimeout !== null) {
                $redis->setOption($optReadTimeout, 0.5);
            }
            if (!$this->authenticateRedis($redis, $config['username'], $config['auth'])) {
                if ($this->lastError === null) {
                    $this->lastError = 'Redis auth failed';
                }
                return null;
            }
            // Безумовно: у persistent-з'єднанні обраний номер БД лишається від
            // попереднього запиту, тож покладатися на дефолт не можна.
            if (!$redis->select($config['db'])) {
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
            // Ключі картинок теговані пер-товарно, тож на лістингу тегів
            // десятки. Розгорнутий вектор роздув би ключ на сотні символів —
            // згортаємо його, лишаючи короткі ключі читабельними.
            $tagSegment = count($parts) > self::MAX_INLINE_TAGS
                ? ':v' . md5(implode('|', $parts))
                : implode('', $parts);
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
            $started = microtime(true);
            $client->incr(self::VERSION_KEY_PREFIX . $tag);
            $this->statSeconds += microtime(true) - $started;
            $this->statRoundTrips++;
            $this->statBumps++;
            $this->bumpedTags[$tag] = $this->now();
            unset($this->versionMemo[$tag], $this->pendingBumps[$tag]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->dropClient();
        }
    }

    /**
     * Схлопує повтори того самого тега в межах короткого вікна. Веб-запит
     * коротший за вікно, тож у ньому bump відбувається рівно раз.
     *
     * Вікно, а не «раз на процес»: StockSync іде чанками по 500 хвилинами в
     * одному процесі, і при схлопуванні на весь процес перший чанк підняв би
     * версію, фронт закешував би напівоновлені дані, а решта чанків стала б
     * no-op — вітрина віддавала б середину імпорту до кінця TTL.
     */
    public function bumpOnce(string $tag): void
    {
        $lastBumpedAt = $this->bumpedTags[$tag] ?? null;
        if ($lastBumpedAt !== null && ($this->now() - $lastBumpedAt) < self::BUMP_COLLAPSE_WINDOW) {
            // Відкладаємо, а не викидаємо: якщо серія на цьому bump і скінчилась,
            // версію більше нікому підняти, і вітрина віддавала б стан початку
            // серії до кінця TTL.
            $this->pendingBumps[$tag] = true;
            if (!$this->pendingFlushRegistered) {
                $this->pendingFlushRegistered = true;
                $this->registerPendingFlush();
            }
            return;
        }
        // Прапорець ставить сам bump() — і лише на успішному INCR. Інакше одна
        // проковтнута помилка назавжди прибрала б усі наступні bump цього тега.
        $this->bump($tag);
    }

    /** Хвіст серії: піднімає версії, борг за якими лишився після схлопування. */
    public function flushPendingBumps(): void
    {
        $tags = array_keys($this->pendingBumps);
        $this->pendingBumps = [];
        foreach ($tags as $tag) {
            $this->bump($tag);
        }
    }

    /** Окремим методом, щоб тест не реєстрував справжній shutdown-хук. */
    protected function registerPendingFlush(): void
    {
        register_shutdown_function([$this, 'flushPendingBumps']);
    }

    protected function now(): float
    {
        return microtime(true);
    }

    /**
     * Після помилки стан persistent-сокета невідомий: непрочитана відповідь
     * лишається в буфері, і наступний запит того самого воркера прочитав би
     * її як відповідь на СВОЮ команду — тобто віддав би чужі дані під своїм
     * ключем. Тому з'єднання викидаємо, а не переюзуємо.
     */
    private function dropClient(): void
    {
        if ($this->client !== null) {
            try { $this->client->close(); } catch (\Throwable $e) {}
        }
        $this->client = null;

        // Дозволяємо перепідключитись: інакше один таймаут вимкнув би і кеш,
        // і — що гірше — інвалідацію до кінця процесу, а CLI-імпорт живе
        // хвилинами. Спроби обмежені, щоб при лежачій Redis не платити
        // таймаут за кожне звернення.
        if ($this->reconnectsLeft > 0) {
            $this->reconnectsLeft--;
            $this->initialized = false;
        }
    }

    public function getRequestStats(): array
    {
        return [
            'hits'        => $this->statHits,
            'misses'      => $this->statMisses,
            'bumps'       => $this->statBumps,
            'round_trips' => $this->statRoundTrips,
            'ms'          => round($this->statSeconds * 1000, 2),
            'error'       => $this->lastError,
        ];
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
            $this->statMisses++;
            return null;
        }
        try {
            $started = microtime(true);
            $data = $client->get($key);
            $this->statSeconds += microtime(true) - $started;
            $this->statRoundTrips++;
            if ($data === false || $data === null) {
                $this->getMemo[$key] = [false, null];
                $this->statMisses++;
                return null;
            }
            $value = $this->safeUnserialize((string) $data);
            if ($value === null) {
                $this->getMemo[$key] = [false, null];
                $this->statMisses++;
                return null;
            }
            $this->getMemo[$key] = [true, $value];
            $this->statHits++;
            return $value;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->dropClient();
            $this->getMemo[$key] = [false, null];
            $this->statMisses++;
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
            $started = microtime(true);
            $raw = $client->mGet($needFetch);
            $this->statSeconds += microtime(true) - $started;
            $this->statRoundTrips++;
            if (!is_array($raw)) {
                $raw = [];
            }
            foreach ($needFetch as $i => $k) {
                $data = $raw[$i] ?? null;
                if ($data === false || $data === null) {
                    $this->getMemo[$k] = [false, null];
                    $result[$k] = null;
                    $this->statMisses++;
                    continue;
                }
                $value = $this->safeUnserialize((string) $data);
                if ($value === null) {
                    $this->getMemo[$k] = [false, null];
                    $result[$k] = null;
                    $this->statMisses++;
                    continue;
                }
                $this->getMemo[$k] = [true, $value];
                $result[$k] = $value;
                $this->statHits++;
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->dropClient();
            foreach ($needFetch as $k) {
                $this->getMemo[$k] = [false, null];
                $result[$k] = null;
                $this->statMisses++;
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
        $ttl = (int) ($ttl ?? $config['ttl']);
        // Явно налаштований TTL поважаємо яким є — підлога потрібна лише щоб
        // не лишитися без TTL узагалі (див. MIN_TTL).
        if ($ttl <= 0) {
            $ttl = max(self::MIN_TTL, (int) $config['ttl']);
        }
        try {
            $data = serialize($value);
            $started = microtime(true);
            $client->setex($key, $ttl, $data);
            $this->statSeconds += microtime(true) - $started;
            $this->statRoundTrips++;
            $this->getMemo[$key] = [true, $value];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->dropClient();
        }
    }
}
