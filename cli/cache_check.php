<?php

/**
 * Перевіряє, що сторінка на влучанні в кеш така сама, як на промаху.
 *
 * Рядки, які різняться між двома однаковими запитами (CSRF, токени форм),
 * визначаються заміром, а не переліком регулярок: склад такого шуму залежить від
 * теми й від того, що ввімкнено, і рано чи пізно розійшовся б із захардкодженим.
 *
 * Контрольна проба наприкінці обов'язкова: без неї «розбіжностей немає» не
 * доводить нічого — порівняння могло зламатись і мовчки порівнювати порожнечу.
 *
 * Чистить кеш і тимчасово рухає дати однієї акції, тож тільки для дев-стенду.
 *
 * Запуск: php Okay/Modules/Sviat/Redis/cli/cache_check.php [--force]
 */

use Okay\Core\Config;
use Okay\Core\Modules\Modules;
use Okay\Core\OkayContainer\OkayContainer;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

chdir(dirname(__DIR__, 5));

require_once('vendor/autoload.php');

/** @var OkayContainer $DI */
$DI = require 'Okay/Core/config/container.php';

/** @var Modules $modules */
$modules = $DI->get(Modules::class);
$modules->startEnabledModules();

/** @var Config $config */
$config = $DI->get(Config::class);
/** @var Settings $settings */
$settings = $DI->get(Settings::class);
/** @var RedisCacheService $redis */
$redis = $DI->get(RedisCacheService::class);

$argv = $_SERVER['argv'] ?? [];
$force = in_array('--force', $argv, true);

// Скрипт чистить кеш і тимчасово рухає дати акції. debug_mode вимкнений — це
// ознака бойового стенду, де такого робити не можна.
if (!$config->get('debug_mode') && !$force) {
    fwrite(STDERR, "debug_mode вимкнений — схоже на прод. Повторіть із --force, якщо це справді дев.\n");
    exit(2);
}

if (!$redis->isEnabled()) {
    fwrite(STDERR, "Redis вимкнений у налаштуваннях модуля — перевіряти нічого.\n");
    exit(2);
}

// Хост важливий: канонічні адреси й абсолютні посилання будуються з нього, і
// під чужим іменем сторінка виходить іншою. У контейнер php-fpm VIRTUAL_HOST не
// передається, тож дочитуємо його з того ж .env, яким піднято стек.
$host = getenv('VIRTUAL_HOST') ?: '';
if ($host === '' && is_file('.env') && preg_match('/^VIRTUAL_HOST=(.+)$/m', (string) file_get_contents('.env'), $m)) {
    $host = trim($m[1], " \t\"'");
}
if ($host === '') {
    fwrite(STDERR, "Не визначив VIRTUAL_HOST — сторінки під чужим іменем хоста порівнювати немає сенсу.\n");
    exit(2);
}
$origin = 'http://nginx';

$urlsFile = 'dev/cache-check-urls.txt';
$urls = [];
if (is_file($urlsFile)) {
    foreach (file($urlsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
            $urls[] = $line;
        }
    }
}
if ($urls === []) {
    $urls = ['/'];
    fwrite(STDERR, "Немає $urlsFile — перевіряю лише головну.\n");
}

/**
 * Сирий клієнт потрібен на SCAN: сервіс його не віддає, а контрольна проба має
 * знайти й зіпсувати конкретний ключ.
 */
function rawClient(Settings $settings): \Redis
{
    $client = new \Redis();
    $client->connect(
        getenv('REDIS_HOST') ?: ($settings->get('sviat__redis__host') ?: '127.0.0.1'),
        (int) (getenv('REDIS_PORT') ?: ($settings->get('sviat__redis__port') ?: 6379)),
        1.0
    );
    $password = getenv('REDIS_PASSWORD') ?: (string) $settings->get('sviat__redis__password');
    if ($password !== '') {
        $client->auth($password);
    }
    $client->select((int) (getenv('REDIS_DB') ?: ($settings->get('sviat__redis__db') ?: 0)));

    return $client;
}

function fetch(string $origin, string $host, string $path): array
{
    $ch = curl_init($origin . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Host: ' . $host],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Усе після </html> — налагоджувальний коментар index.php із часом і піком
    // пам'яті. Він залежить від того, скільки роботи зроблено, тобто на промаху
    // й на влучанні різний за визначенням, і сторінкою не є.
    $end = strripos($body, '</html>');
    if ($end !== false) {
        $body = substr($body, 0, $end + 7);
    }

    return [$code, explode("\n", $body)];
}

/** Індекси рядків, які різняться між двома відповідями. */
function differingLines(array $a, array $b): array
{
    $out = [];
    $max = max(count($a), count($b));
    for ($i = 0; $i < $max; $i++) {
        if (($a[$i] ?? null) !== ($b[$i] ?? null)) {
            $out[$i] = true;
        }
    }

    return $out;
}

function flushCache(RedisCacheService $redis, \Redis $raw): void
{
    // Звіту мало: перевіряємо ще й наслідок, бо порівняння на невичищеному
    // кеші мовчки міряло б не те.
    if (!$redis->flushAll() || $raw->dbSize() > 0) {
        fwrite(STDERR, "Кеш не очистився — далі перевірка нічого не варта.\n");
        exit(3);
    }
}

/**
 * @return array{0:bool,1:string} чи збіглося і що саме розійшлось
 */
function compareMissHit(string $origin, string $host, string $path, RedisCacheService $redis, \Redis $raw): array
{
    fetch($origin, $host, $path);

    // Калібрування шуму: об'єднання попарних розбіжностей трьох однакових
    // запитів. Об'єднання, а не перетин — рядок, який мигає зрідка, теж шум.
    [, $a] = fetch($origin, $host, $path);
    [, $b] = fetch($origin, $host, $path);
    [, $c] = fetch($origin, $host, $path);
    $noise = differingLines($a, $b) + differingLines($b, $c) + differingLines($a, $c);

    flushCache($redis, $raw);
    [$missCode, $miss] = fetch($origin, $host, $path);
    fetch($origin, $host, $path);
    [$hitCode, $hit] = fetch($origin, $host, $path);

    if ($missCode !== 200 || $hitCode !== 200) {
        return [false, "HTTP $missCode на промаху, $hitCode на влучанні"];
    }

    $real = [];
    foreach (differingLines($miss, $hit) as $i => $_) {
        if (!isset($noise[$i])) {
            $real[] = $i;
        }
    }
    if ($real === []) {
        return [true, 'шум ' . count($noise) . ' рядків'];
    }

    // Показуємо бік промаху, а якщо там порожньо — бік влучання: при різній
    // довжині сторінок перший розбіжний індекс може бути лише в одному з них.
    $sample = trim((string) ($miss[$real[0]] ?? ''));
    if ($sample === '') {
        $sample = trim((string) ($hit[$real[0]] ?? ''));
    }
    if (mb_strlen($sample) > 110) {
        $sample = mb_substr($sample, 0, 110) . '…';
    }

    return [false, sprintf('%s, від рядка #%d: %s', describeDelta($miss, $hit), $real[0] + 1, $sample)];
}

/**
 * Скільки рядків насправді додалось і зникло. Порівняння за індексом після
 * вставки одного рядка показало б розбіжним увесь хвіст сторінки й перебільшило
 * б масштаб у сотні разів.
 */
function describeDelta(array $miss, array $hit): string
{
    $left = array_count_values(array_map('trim', $miss));
    $right = array_count_values(array_map('trim', $hit));
    $added = 0;
    $removed = 0;
    foreach ($left + $right as $line => $_) {
        $delta = ($right[$line] ?? 0) - ($left[$line] ?? 0);
        $delta > 0 ? $added += $delta : $removed -= $delta;
    }

    return sprintf('на влучанні -%d/+%d рядків', $removed, $added);
}

/**
 * Псує закешований лістинг. Без цієї проби «розбіжностей немає» нічого не
 * означає — порівняння могло зламатись і мовчки порівнювати порожнечу.
 */
function poisonListing(\Redis $raw, string $prefix): int
{
    $iterator = null;
    $spoiled = 0;
    while ($keys = $raw->scan($iterator, $prefix . 'helpers:products_get_list:*', 500)) {
        foreach ($keys as $key) {
            $value = @unserialize((string) $raw->get($key), ['allowed_classes' => ['stdClass']]);
            if (!is_array($value) || $value === []) {
                continue;
            }
            // Значення може лежати як є або в конверті stale-while-revalidate.
            // Без цієї гілки проба мовчки псувала б конверт замість товарів і
            // «контроль пройдено» не означало б нічого.
            $products = isset($value['__swr']) ? ($value['v'] ?? null) : $value;
            if (!is_array($products) || $products === []) {
                continue;
            }
            $touched = false;
            foreach ($products as $product) {
                if (isset($product->name)) {
                    $product->name = 'CACHECHECK-' . $product->name;
                    $touched = true;
                }
            }
            if (!$touched) {
                continue;
            }
            $ttl = $raw->ttl($key);
            $raw->setex($key, $ttl > 0 ? $ttl : 300, serialize($value));
            $spoiled++;
        }
    }

    return $spoiled;
}

$raw = rawClient($settings);
$prefix = getenv('REDIS_PREFIX') ?: ($settings->get('sviat__redis__prefix') ?: 'okay:');

// Одна проба до початку: інакше недосяжний веб-сервер виглядав би як десяток
// провалених перевірок, а не як те, чим є.
[$preflight] = fetch($origin, $host, $urls[0]);
if ($preflight !== 200) {
    fwrite(STDERR, "Сайт не відповідає на $origin{$urls[0]} (Host: $host): HTTP $preflight\n");
    exit(2);
}

// --- акція: другий стан матриці ------------------------------------------

$promoEntity = null;
$promoSnapshot = null;
$snapshotFile = 'dev/cache-check-promo-snapshot.json';
if (class_exists(\Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity::class)) {
    try {
        $promoEntity = $DI->get(\Okay\Core\EntityFactory::class)
            ->get(\Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity::class);
        foreach ($promoEntity->find() as $promo) {
            if (!empty($promo->visible) && !empty($promo->has_date_range)) {
                $promoSnapshot = [
                    'id'         => (int) $promo->id,
                    'date_start' => $promo->date_start,
                    'date_end'   => $promo->date_end,
                ];
                break;
            }
        }
    } catch (\Throwable $e) {
        $promoSnapshot = null;
    }
}

$states = [['без акції', false]];
if ($promoSnapshot !== null) {
    // Знімок на диск: якщо прогін обірвуть, буде з чого відновити вручну.
    file_put_contents($snapshotFile, json_encode($promoSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $states[] = ['з акцією #' . $promoSnapshot['id'], true];
} else {
    echo "Акції з датами не знайшов — перевіряю лише стан без акції.\n";
}

$failed = 0;
$checked = 0;

try {
    foreach ($states as [$label, $activatePromo]) {
        if ($activatePromo) {
            $promoEntity->update($promoSnapshot['id'], [
                'date_start' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'date_end'   => date('Y-m-d H:i:s', strtotime('+1 day')),
            ]);
        }

        echo "\n== $label\n";
        foreach ($urls as $path) {
            [$ok, $note] = compareMissHit($origin, $host, $path, $redis, $raw);
            $checked++;
            if (!$ok) {
                $failed++;
            }
            printf("  %-5s %-44s %s\n", $ok ? 'ok' : 'FAIL', $path, $note);
        }
    }
} finally {
    if ($promoSnapshot !== null && $promoEntity !== null) {
        $promoEntity->update($promoSnapshot['id'], [
            'date_start' => $promoSnapshot['date_start'],
            'date_end'   => $promoSnapshot['date_end'],
        ]);
        $restored = $promoEntity->get($promoSnapshot['id']);
        $intact = $restored !== null
            && $restored->date_start === $promoSnapshot['date_start']
            && $restored->date_end === $promoSnapshot['date_end'];
        @unlink($snapshotFile);
        printf("\nДати акції #%d %s\n", $promoSnapshot['id'], $intact ? 'відновлено й звірено.' : 'ВІДНОВИТИ НЕ ВДАЛОСЬ.');
    }
}

// --- контроль: підміна в кеші має бути помічена ---------------------------

echo "\n== контроль\n";
$controlPath = $urls[0];
flushCache($redis, $raw);
fetch($origin, $host, $controlPath);
// Шум віднімаємо й тут: без цього контроль зарахувався б на самих лише
// CSRF-токенах, тобто підтверджував би зіркість, якої немає.
[, $a] = fetch($origin, $host, $controlPath);
[, $b] = fetch($origin, $host, $controlPath);
[, $clean] = fetch($origin, $host, $controlPath);
$controlNoise = differingLines($a, $b) + differingLines($b, $clean) + differingLines($a, $clean);
$spoiled = poisonListing($raw, $prefix);
[, $dirty] = fetch($origin, $host, $controlPath);
$controlDiff = count(array_diff_key(differingLines($clean, $dirty), $controlNoise));
flushCache($redis, $raw);

if ($spoiled === 0) {
    printf("  FAIL  на %s немає закешованого лістингу — контроль не відпрацював\n", $controlPath);
    $failed++;
} elseif ($controlDiff < 5) {
    printf("  FAIL  зіпсовано ключів: %d, а різниця лише %d рядків — перевірка сліпа\n", $spoiled, $controlDiff);
    $failed++;
} else {
    printf("  ok    зіпсовано ключів: %d, помічено %d рядків різниці\n", $spoiled, $controlDiff);
}

echo "\n";
if ($failed > 0) {
    printf("ПРОВАЛ: %d з %d перевірок\n", $failed, $checked + 1);
    exit(1);
}
printf("Усе зійшлось: %d перевірок\n", $checked + 1);
exit(0);
