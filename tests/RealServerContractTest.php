<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use PHPUnit\Framework\TestCase;

/**
 * Те, чого фейк показати не може: чи phpredis додає OPT_PREFIX до ключів eval()
 * і чи SCAN бачить фізичні імена. Без сервера в оточенні тест пропускається.
 *
 * Працює у власній логічній DB, бо перевіряє в ній чужі ключі.
 */
class RealServerContractTest extends TestCase
{
    private const int TEST_DB = 14;

    private \Redis $raw;
    private array $envBackup = [];

    protected function setUp(): void
    {
        $host = getenv('REDIS_HOST');
        if (!class_exists('\\Redis') || $host === false || $host === '') {
            $this->markTestSkipped('потрібні phpredis і REDIS_HOST');
        }

        $password = getenv('REDIS_PASSWORD');
        $this->raw = new \Redis();
        try {
            $this->raw->connect($host, (int) (getenv('REDIS_PORT') ?: 6379), 1.0);
            if ($password !== false && $password !== '') {
                $this->raw->auth($password);
            }
            $this->raw->select(self::TEST_DB);
        } catch (\Throwable $e) {
            unset($this->raw);
            $this->markTestSkipped('Redis недоступний: ' . $e->getMessage());
        }
        $this->raw->flushDB();

        foreach (['REDIS_DB', 'REDIS_PREFIX', 'REDIS_ENABLED'] as $name) {
            $this->envBackup[$name] = getenv($name);
        }
        putenv('REDIS_DB=' . self::TEST_DB);
        putenv('REDIS_ENABLED=1');
    }

    protected function tearDown(): void
    {
        if (!isset($this->raw)) {
            return;
        }
        $this->raw->flushDB();
        $this->raw->close();
        foreach ($this->envBackup as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }

    private function service(string $prefix): RedisCacheService
    {
        putenv('REDIS_PREFIX=' . $prefix);
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(
            fn (string $k) => $k === 'sviat__redis__default_ttl' ? 600 : null
        );

        return new class ($settings) extends RedisCacheService {
            public array $deferred = [];

            protected function defer(callable $task): void
            {
                $this->deferred[] = $task;
            }
        };
    }

    public function testFlushRemovesEveryModuleKeyAndNothingElse(): void
    {
        for ($i = 0; $i < 1300; $i++) {
            $this->raw->set("okay:helpers:k$i", 'v');
        }
        $this->raw->set('okay:helpers:ver:pver:1', '5');
        $this->raw->set('PHPREDIS_SESSION:abc', 'сесія');
        $this->raw->set('okay:queue:jobs', 'черга під тим самим префіксом');
        $this->raw->set('other:helpers:x', 'сусід');

        self::assertTrue($this->service('okay:')->flushAll());

        self::assertSame(3, $this->raw->dbSize());
        self::assertSame('сесія', $this->raw->get('PHPREDIS_SESSION:abc'));
    }

    public function testFlushWithGlobPrefixKeepsNeighbour(): void
    {
        $this->raw->set('shop*:helpers:own', 'своє');
        $this->raw->set('shop2:helpers:neighbour', 'сусід');

        self::assertTrue($this->service('shop*:')->flushAll());

        self::assertSame(0, $this->raw->exists('shop*:helpers:own'));
        self::assertSame(1, $this->raw->exists('shop2:helpers:neighbour'));
    }

    public function testFlushOnEmptyDbSucceeds(): void
    {
        self::assertTrue($this->service('okay:')->flushAll());
    }

    public function testLockReleaseSeesPrefixedKey(): void
    {
        $service = $this->service('okay:');
        $service->remember('t', [], [], fn () => 'старе', 60);
        $this->expireAllEnvelopes();

        $service = $this->service('okay:');
        self::assertSame('старе', $service->remember('t', [], [], fn () => 'нове', 60));
        $locks = $this->raw->keys('okay:*:lock');
        self::assertCount(1, $locks, 'замок лежить під префіксом');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->raw->get($locks[0]));

        ($service->deferred[0])();

        self::assertSame([], $this->raw->keys('okay:*:lock'), 'якби eval не додав префікс, замок лишився б');
    }

    public function testExpiredOwnerLeavesReplacementLockOnServer(): void
    {
        $service = $this->service('okay:');
        $service->remember('t', [], [], fn () => 'старе', 60);
        $this->expireAllEnvelopes();

        $service = $this->service('okay:');
        $service->remember('t', [], [], fn () => 'нове', 60);
        [$lock] = $this->raw->keys('okay:*:lock');
        $this->raw->set($lock, 'токен-іншого-воркера');

        ($service->deferred[0])();

        self::assertSame('токен-іншого-воркера', $this->raw->get($lock));
    }

    private function expireAllEnvelopes(): void
    {
        foreach ($this->raw->keys('okay:helpers:*') as $key) {
            $envelope = unserialize($this->raw->get($key));
            if (is_array($envelope) && isset($envelope['f'])) {
                $envelope['f'] = 1.0;
                $this->raw->set($key, serialize($envelope));
            }
        }
    }
}
