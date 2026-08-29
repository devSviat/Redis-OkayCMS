<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeRedisClient.php';
require_once __DIR__ . '/PrivateAccess.php';

/**
 * Дві речі, які тримає стан на диску: не платити таймаут з'єднання кожним
 * запитом і не загубити підняття версії, зроблене в хвилину недоступності.
 */
class BreakerAndPendingBumpsTest extends TestCase
{
    use PrivateAccess;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/okay-redis-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Симлінки прибираємо саме unlink(), не заходячи в них: інакше приберемо
        // те, на що вони показують.
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            if (is_dir($path) && !is_link($path)) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($this->dir);
    }

    private function makeService(?FakeRedisClient $client): TestableRedisCacheService
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) {
            return [
                'sviat__redis__enabled'     => true,
                'sviat__redis__host'        => '127.0.0.1',
                'sviat__redis__port'        => 6379,
                'sviat__redis__db'          => 0,
                'sviat__redis__prefix'      => 'okay:',
                'sviat__redis__default_ttl' => 600,
            ][$key] ?? null;
        });

        $service = new TestableRedisCacheService($settings);
        $service->stateDir = $this->dir;
        $service->fakeClient = $client;

        return $service;
    }

    private function downFile(): string
    {
        return $this->dir . '/down';
    }

    private function bumpsFile(): string
    {
        return $this->dir . '/bumps';
    }

    public function testFailedConnectionArmsTheBreaker(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $service = $this->makeService(null);

        $service->get('anything');

        $this->assertFileExists($this->downFile(), 'провал з\'єднання має лишити слід для наступних запитів');
        $this->assertSame(1, $service->connectAttempts);
    }

    public function testArmedBreakerSuppressesTheNextAttempt(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        touch($this->downFile());
        $service = $this->makeService(null);

        $service->get('anything');

        $this->assertSame(0, $service->connectAttempts, 'у вікні запобіжника до Redis не ходимо зовсім');
        $this->assertTrue(
            $service->getRequestStats()['suppressed'],
            'придушену спробу треба відрізняти від справжньої помилки, інакше лог заллє однаковими рядками'
        );
    }

    public function testARealFailureIsNotMarkedAsSuppressed(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $service = $this->makeService(null);

        $service->get('anything');

        $this->assertFalse(
            $service->getRequestStats()['suppressed'],
            'перший провал має дійти до логу — саме він називає причину'
        );
    }

    public function testExpiredBreakerLetsOneAttemptThrough(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        touch($this->downFile(), time() - 3600);
        $service = $this->makeService(null);

        $service->get('anything');

        $this->assertSame(1, $service->connectAttempts, 'після вікна одна спроба має пройти');
    }

    public function testSuccessfulConnectionLeavesNoTrace(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $service = $this->makeService(new FakeRedisClient());

        $service->get('anything');

        $this->assertFileDoesNotExist($this->downFile());
        $this->assertFileDoesNotExist($this->bumpsFile(), 'на здоровій системі нічого писати не треба');
    }

    public function testRecoveryClearsTheBreaker(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        touch($this->downFile(), time() - 3600);
        $service = $this->makeService(new FakeRedisClient());

        $service->get('anything');

        $this->assertFileDoesNotExist($this->downFile());
    }

    public function testBumpThatCouldNotReachRedisIsKept(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $service = $this->makeService(null);

        $service->bump('pver:42');
        $service->bump('plist:global');

        $kept = file($this->bumpsFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertSame(['pver:42', 'plist:global'], $kept);
    }

    public function testKeptBumpsAreAppliedOnTheNextConnection(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        file_put_contents($this->bumpsFile(), "pver:42\nplist:global\npver:42\n");
        $client = new FakeRedisClient();
        $service = $this->makeService($client);

        $service->get('anything');

        $this->assertSame(1, $client->store['helpers:ver:pver:42'] ?? null);
        $this->assertSame(1, $client->store['helpers:ver:plist:global'] ?? null);
        $this->assertFileDoesNotExist($this->bumpsFile(), 'борг віддано — списку більше немає');
    }

    public function testOverflowingListIsClosedByWipingTheCache(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        file_put_contents($this->bumpsFile(), "*\n");
        $client = new FakeRedisClient();
        $client->store['helpers:something'] = 'stale';
        $service = $this->makeService($client);

        $service->get('anything');

        $this->assertSame([], $client->store, 'точний перелік втрачено — лишається скинути все');
        $this->assertFileDoesNotExist($this->bumpsFile());
    }

    /**
     * Каталог tmp доступний на запис усім, а імена файлів передбачувані: без
     * цих перевірок підкладений симлінк перенаправив би запис у чужий файл.
     */
    public function testStateDirIsRejectedWhenItIsNotOursAlone(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $method = new \ReflectionMethod(RedisCacheService::class, 'isOwnPrivateDir');
        self::accessible($method);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : 0;

        $own = $this->dir . '/own';
        mkdir($own, 0700);
        $this->assertTrue($method->invoke(null, $own, $uid), 'власний каталог 0700 годиться');

        $shared = $this->dir . '/shared';
        mkdir($shared, 0777);
        $this->assertFalse($method->invoke(null, $shared, $uid), 'у каталог, куди пише хтось іще, стан не кладемо');

        $link = $this->dir . '/link';
        symlink($own, $link);
        $this->assertFalse($method->invoke(null, $link, $uid), 'симлінк не має видавати себе за наш каталог');

        $this->assertFalse($method->invoke(null, $this->dir . '/missing', $uid));
    }

    public function testWriteThroughASymlinkIsRefused(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $target = $this->dir . '/victim';
        file_put_contents($target, "before\n");
        symlink($target, $this->bumpsFile());

        $service = $this->makeService(null);
        $service->bump('pver:42');

        $this->assertSame("before\n", file_get_contents($target), 'запис не має йти за посиланням');
    }

    /**
     * Помилка посеред віддачі боргу роняє з'єднання; наступний bump підніме
     * його заново, і без сторожа віддача пішла б удруге, вкладено.
     */
    public function testRedeliveryDoesNotNestWhenTheConnectionDropsMidway(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        file_put_contents($this->bumpsFile(), "pver:1\npver:2\npver:3\n");
        $client = new FakeRedisClient();
        $client->failIncrTimes = 1;
        $service = $this->makeService($client);

        $service->get('anything');

        $incrs = array_filter($client->calls, static fn($c) => $c[0] === 'incr');
        $this->assertLessThanOrEqual(3, count($incrs), 'кожен тег піднімаємо не більше разу за прохід');
    }

    /**
     * Список забирається перейменуванням, і якщо захопити не вдалось — борг має
     * лишитись на місці. Схема «прочитати й видалити» натомість зносить файл
     * навіть тоді, коли віддати його не змогла.
     */
    public function testDebtSurvivesAFailedClaim(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        file_put_contents($this->bumpsFile(), "pver:1\n");
        // Займаємо ім'я, під яким сервіс намагатиметься захопити список.
        mkdir($this->bumpsFile() . '.' . getmypid());

        $client = new FakeRedisClient();
        $service = $this->makeService($client);
        $service->get('anything');

        $this->assertFileExists($this->bumpsFile(), 'нехай краще лишиться, ніж зникне неврахованим');
        $this->assertSame([], $client->store, 'нічого не піднімали — захопити не вдалось');

        rmdir($this->bumpsFile() . '.' . getmypid());
    }

    /**
     * Перелік уже забрано, а скинути кеш не вдалось. Без повторного запису
     * борг зник би, і стара ціна лишилась би до кінця TTL уже без сліду.
     */
    public function testFailedWipeKeepsTheOverflowMark(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        file_put_contents($this->bumpsFile(), "*\n");
        $client = new FakeRedisClient();
        $client->failFlush = true;
        $service = $this->makeService($client);

        $service->get('anything');

        $this->assertStringContainsString('*', (string) @file_get_contents($this->bumpsFile()));
    }

    public function testListStopsGrowingPastItsCeiling(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $service = $this->makeService(null);

        for ($i = 0; $i < 2000; $i++) {
            $service->bump('pver:' . $i);
        }

        $this->assertLessThan(20000, filesize($this->bumpsFile()));
        $this->assertStringContainsString('*', (string) file_get_contents($this->bumpsFile()));
    }
}

/**
 * Підміняє два шви: створення з'єднання й місце для файлів стану. Обидва
 * оголошені protected саме для цього — так само, як defer() і now() поруч.
 */
class TestableRedisCacheService extends RedisCacheService
{
    public string $stateDir = '';
    public ?FakeRedisClient $fakeClient = null;
    public int $connectAttempts = 0;

    protected function stateFile(string $kind): ?string
    {
        return $this->stateDir . '/' . $kind;
    }

    protected function connectBare(bool $applyKeyPrefix = true, bool $persistent = false)
    {
        $this->connectAttempts++;

        return $this->fakeClient;
    }
}
