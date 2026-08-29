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

    /** Скільки протухле значення ще можна віддавати, поки йде перерахунок. */
    private const SWR_GRACE = 3600;

    /** Замок на перерахунок; переживає час самого перерахунку з запасом. */
    private const SWR_LOCK_TTL = 60;

    /**
     * Конверти лежать під власним іменем. Інакше відкат модуля віддав би
     * конверт старому коду, який чекає на голе значення, — і це не помилка
     * кешу, а TypeError у викликача.
     */
    private const SWR_NAME_SUFFIX = ':swr';

    /** @var array<string, true> ключі, перерахунок яких уже заплановано в цьому запиті */
    private array $refreshing = [];

    /** Вікно схлопування повторних bump одного тега, секунди. */
    private const BUMP_COLLAPSE_WINDOW = 1.0;

    /**
     * Скільки не торкатись Redis після провалу з'єднання.
     *
     * Таймаут з'єднання не поширюється на резолв імені: зниклий із DNS хост
     * висить секундами, скільки б не стояло в connect(). Без пам'яті між
     * запитами цю ціну платить кожен запит, тобто сайт формально живий і
     * фактично лежить.
     */
    private const BREAKER_TTL = 10;

    /**
     * Стеля списку недоставлених bump. Довший простій дешевше й надійніше
     * закрити повним скиданням кешу, ніж тягнути список без краю.
     */
    private const PENDING_BUMPS_BYTES = 16384;

    /** Мітка «лік втрачено»: на відновленні скидаємо кеш цілком. */
    private const PENDING_OVERFLOW = '*';

    private array $helperTtlCache = [];
    private array $versionMemo = [];
    private array $getMemo = [];
    /** tag => час останнього успішного bump (unix float). */
    private array $bumpedTags = [];
    /** tag => true для bump, які схлопнуло вікно і які ще треба зробити. */
    private array $pendingBumps = [];
    private bool $pendingFlushRegistered = false;
    private int $reconnectsLeft = 2;
    private bool $breakerSuppressed = false;
    private bool $replayingBumps = false;
    private bool $stateDirResolved = false;
    private ?string $stateDirMemo = null;

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

        $trippedAt = $this->breakerTrippedAt();
        if ($trippedAt !== null && (time() - $trippedAt) < self::BREAKER_TTL) {
            $this->lastError = 'Redis unreachable, retry suppressed for ' . self::BREAKER_TTL . 's';
            $this->breakerSuppressed = true;
            return null;
        }
        // Позначку оновлюємо ДО спроби: поки цей запит платить таймаут,
        // паралельні бачать свіжий запобіжник і не платять його теж.
        if ($trippedAt !== null) {
            $this->armBreaker();
        }

        $this->client = $this->connectBare(true, true);
        if ($this->client === null) {
            $this->armBreaker();
            return null;
        }

        if ($trippedAt !== null) {
            $this->disarmBreaker();
        }
        $this->replayPendingBumps();

        return $this->client;
    }

    /**
     * Власний каталог у tmp, а не файли поруч із чужими.
     *
     * Каталог tmp доступний на запис усім, а ім'я файлу передбачуване, тож
     * підкладений заздалегідь симлінк перенаправив би наш запис у будь-який
     * файл, доступний нам на запис. Тому не «шлях існує», а «каталог наш і
     * тільки наш»: uid у назві (планувальник працює від root, веб від
     * www-data), права 0700 і перевірка через lstat, яка не йде за посиланням.
     *
     * Не в cache/ застосунку: його вайпає entrypoint при старті, а разом із ним
     * зник би й борг по bump, тобто саме те, що має пережити перезапуск.
     */
    private function stateDir(): ?string
    {
        if ($this->stateDirResolved) {
            return $this->stateDirMemo;
        }
        $this->stateDirResolved = true;

        $uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        if ($uid < 0) {
            // Без posix не дізнатись, від кого ми працюємо, а спільна назва
            // звела б докупи файли різних користувачів.
            return null;
        }
        $dir = sys_get_temp_dir() . '/okay-redis-' . $uid;
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        // Чужий або підмінений каталог: краще лишитись без запобіжника, ніж
        // писати кудись, куди нас завели.
        return $this->stateDirMemo = (self::isOwnPrivateDir($dir, $uid) ? $dir : null);
    }

    /** lstat, а не stat: за посиланням не йдемо, інакше перевіряли б ціль. */
    private static function isOwnPrivateDir(string $dir, int $uid): bool
    {
        $stat = @lstat($dir);

        return $stat !== false
            && ($stat['mode'] & 0170000) === 0040000
            && $stat['uid'] === $uid
            && ($stat['mode'] & 0077) === 0;
    }

    /** Адреса Redis у назві — щоб два сервери не ділили ні запобіжник, ні борг. */
    protected function stateFile(string $kind): ?string
    {
        $dir = $this->stateDir();
        if ($dir === null) {
            return null;
        }
        $config = $this->loadConfig();
        $target = substr(md5($config['host'] . ':' . $config['port'] . ':' . $config['db']), 0, 8);

        return $dir . '/' . $kind . '-' . $target;
    }

    private function breakerTrippedAt(): ?int
    {
        $file = $this->stateFile('down');
        if ($file === null) {
            return null;
        }
        $at = @filemtime($file);

        return $at === false ? null : $at;
    }

    private function armBreaker(): void
    {
        $file = $this->stateFile('down');
        if ($file !== null && !is_link($file)) {
            @touch($file);
        }
    }

    private function disarmBreaker(): void
    {
        $file = $this->stateFile('down');
        if ($file !== null) {
            @unlink($file);
        }
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
    protected function connectBare(bool $applyKeyPrefix = true, bool $persistent = false)
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
            } else {
                // Кнопка «перевірити» в адмінці — сигнал, що конфіг полагодили.
                // Змушувати чекати вікно запобіжника після цього немає сенсу.
                $this->disarmBreaker();
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        } finally {
            try { $client->close(); } catch (\Throwable $e) {}
        }
    }

    public function flushAll(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $client = $this->connectBare(false);
        if (!$client) {
            return false;
        }
        try {
            $client->flushDB();
            $this->versionMemo = [];
            $this->getMemo = [];

            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
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
            $this->recordUndeliveredBump($tag);
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
            $this->recordUndeliveredBump($tag);
            $this->dropClient();
        }
    }

    /**
     * Недоставлений bump переживає процес.
     *
     * Інакше зміна ціни чи наявності в адмінці, зроблена в хвилину недоступності
     * Redis, зникає безслідно: у базі нове значення, у кеші старе, і вітрина
     * віддає стару ціну до кінця TTL. Тег дописуємо у файл і піднімаємо версію
     * при першому ж вдалому з'єднанні.
     */
    private function recordUndeliveredBump(string $tag): void
    {
        $file = $this->stateFile('bumps');
        if ($file === null || is_link($file)) {
            return;
        }
        // Без скидання кешу stat filesize() віддає розмір, побачений уперше, і
        // стеля не спрацьовує ніколи — на PHP 8.0 це видно, на 8.5 маскується.
        clearstatcache(true, $file);
        $size = @filesize($file);
        if ($size !== false && $size > self::PENDING_BUMPS_BYTES) {
            // Список переріс стелю — далі точний перелік недосяжний, і чесніше
            // визнати це зараз, ніж мовчки загубити хвіст.
            @file_put_contents($file, self::PENDING_OVERFLOW . "\n", LOCK_EX);
            return;
        }
        @file_put_contents($file, $tag . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Борг за час недоступності: віддаємо його одразу після з'єднання. */
    private function replayPendingBumps(): void
    {
        // Помилка посеред віддачі боргу роняє з'єднання, а наступний bump
        // піднімає його заново — і той викликав би віддачу вкладено.
        if ($this->replayingBumps) {
            return;
        }
        $file = $this->stateFile('bumps');
        if ($file === null || is_link($file)) {
            return;
        }
        // Забираємо список перейменуванням, а не «прочитати й видалити»: між
        // цими двома діями сусідній воркер устигає дописати тег, і той зникає
        // разом із файлом. Перейменування атомарне, а те, що допишуть після
        // нього, лягає у новий файл і дочекається наступного разу.
        $claimed = $file . '.' . getmypid();
        if (!@rename($file, $claimed)) {
            return;
        }
        $tags = @file($claimed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        @unlink($claimed);
        if (!is_array($tags) || $tags === []) {
            return;
        }

        $this->replayingBumps = true;
        try {
            if (in_array(self::PENDING_OVERFLOW, $tags, true)) {
                if (!$this->flushAll()) {
                    // Список ми вже забрали, а скинути кеш не вдалось — без
                    // цього рядка борг зник би, і стара ціна лишилась би до
                    // кінця TTL уже без жодного сліду.
                    $this->recordUndeliveredBump(self::PENDING_OVERFLOW);
                }

                return;
            }
            foreach (array_unique($tags) as $tag) {
                $this->bump($tag);
            }
        } finally {
            $this->replayingBumps = false;
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

    /**
     * Знецінює все, що залежить від цін і складу лістингів.
     *
     * Названий шов для сусідніх модулів: інакше кожен, кому треба скинути
     * товарний кеш, мусив би знати наш словник тегів і ламався б від його зміни.
     */
    public function invalidateProductData(): void
    {
        $this->bumpOnce(CacheTags::PRODUCTS_ALL);
        $this->bumpOnce(CacheTags::PRODUCTS_LIST);
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
            // Справжню причину вже записав той запит, який звів запобіжник.
            // Повторювати її кожним придушеним — топити лог у собі.
            'suppressed'  => $this->breakerSuppressed,
        ];
    }

    /**
     * Значення з кешу; протухле віддається одразу, а перерахунок їде після
     * відповіді. Інакше кожні TTL секунд комусь із відвідувачів дістається
     * повний перерахунок — на сторінці бренду це секунда замість десятих.
     *
     * Свіжість тримає конверт усередині значення, а не TTL ключа: TTL тут лише
     * страховка від витіснення, коректність дає версія тега в імені ключа.
     *
     * Продюсер має бути чистим: на протухлому влучанні його викликають ще раз
     * після відповіді, коли вивід уже відправлено, а сесію закрито заради її
     * файлового замка. Метод із побічним ефектом (запис у $_SESSION, лист,
     * лічильник) сюди не загортати.
     *
     * @param string[] $tags
     * @param mixed[] $args
     * @param callable $producer що порахувати при промаху
     * @param callable|null $onHit чим прогнати значення з кешу (ланцюг розширень)
     * @return mixed
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
        if (!$this->isEnabled()) {
            return $producer();
        }

        $key = $this->makeVersionedKey($name . self::SWR_NAME_SUFFIX, $tags, $args);
        $cached = $this->get($key);

        if ($cached !== null) {
            [$value, $freshUntil] = $this->unwrap($cached);
            if ($freshUntil !== null && $this->now() >= $freshUntil) {
                $this->scheduleRefresh($key, $producer, $ttl, $worthStoring);
            }

            return $onHit === null ? $value : $onHit($value);
        }

        $value = $producer();
        if ($worthStoring === null || $worthStoring($value)) {
            $this->storeFresh($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * Гола величина сюди вже не дійде: під ключем із SWR_NAME_SUFFIX пише лише
     * storeFresh(). Розгортання лишається страховкою від чужого запису, і таке
     * значення вважаємо свіжим — інакше воно спричинило б лавину перерахунків.
     *
     * @return array{0: mixed, 1: float|null}
     */
    private function unwrap($cached): array
    {
        if (is_array($cached) && isset($cached['__swr'], $cached['v'], $cached['f'])) {
            return [$cached['v'], (float) $cached['f']];
        }

        return [$cached, null];
    }

    private function storeFresh(string $key, $value, ?int $ttl): void
    {
        $ttl = (int) ($ttl ?: $this->loadConfig()['ttl']);
        if ($ttl <= 0) {
            $ttl = self::MIN_TTL;
        }

        $envelope = ['__swr' => 1, 'v' => $value, 'f' => $this->now() + $ttl];
        // Фізичний TTL із запасом: протухле значення ще має чим відповісти,
        // поки йде фоновий перерахунок.
        $this->set($key, $envelope, $ttl + max($ttl, self::SWR_GRACE));
    }

    /**
     * Перерахунок після відповіді й лише в одному процесі: без замка сплеск
     * трафіку запустив би його стільки разів, скільки прийшло запитів.
     */
    private function scheduleRefresh(string $key, callable $producer, ?int $ttl, ?callable $worthStoring = null): void
    {
        if (isset($this->refreshing[$key])) {
            return;
        }
        // Ставимо позначку до спроби замка: інакше кожен повторний виклик того
        // самого ключа в межах запиту знову ходив би в Redis.
        $this->refreshing[$key] = true;

        if (!$this->acquireRefreshLock($key)) {
            return;
        }

        $this->defer(function () use ($key, $producer, $ttl, $worthStoring): void {
            try {
                $value = $producer();
                if ($worthStoring !== null && !$worthStoring($value)) {
                    // Порожньому перерахунку місця в кеші немає, але замок
                    // знімаємо: інакше ключ лишився б без спроб до кінця SWR_LOCK_TTL.
                    $this->releaseRefreshLock($key);
                    return;
                }
                $this->storeFresh($key, $value, $ttl);
                // Без цього замок тримає ключ SWR_LOCK_TTL, і налаштований TTL
                // менший за нього просто не діяв би.
                $this->releaseRefreshLock($key);
            } catch (\Throwable $e) {
                // Протухле значення лишається на місці — краще старе, ніж порожньо.
                // Замок не знімаємо: хай пауза перед наступною спробою.
                $this->lastError = 'SWR refresh failed: ' . $e->getMessage();
            }
        });
    }

    /** Робота після відповіді. Під CLI вона просто виконається в кінці процесу. */
    protected function defer(callable $task): void
    {
        register_shutdown_function(function () use ($task): void {
            $this->runDeferred($task);
        });
    }

    /** Тіло відкладеної роботи окремо: shutdown-функцію інакше не викликати з тесту. */
    protected function runDeferred(callable $task): void
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        // Сесію PHP закриває аж після shutdown-функцій, тож без цього перерахунок
        // тримає її файловий замок — і наступний запит того самого відвідувача
        // стоїть на session_start() рівно стільки, скільки ми йому зекономили.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $task();
    }

    private function refreshLockKey(string $key): string
    {
        return $key . ':lock';
    }

    private function releaseRefreshLock(string $key): void
    {
        $client = $this->initClient();
        if (!$client) {
            return;
        }

        try {
            $client->del($this->refreshLockKey($key));
        } catch (\Throwable $e) {
            // Замок і сам протухне через SWR_LOCK_TTL.
            $this->lastError = $e->getMessage();
            $this->dropClient();
        }
    }

    private function acquireRefreshLock(string $key): bool
    {
        $client = $this->initClient();
        if (!$client) {
            return false;
        }

        try {
            return (bool) $client->set($this->refreshLockKey($key), '1', ['nx', 'ex' => self::SWR_LOCK_TTL]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->dropClient();
            return false;
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
