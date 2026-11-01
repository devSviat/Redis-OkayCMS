<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeRedisClient.php';
require_once __DIR__ . '/PrivateAccess.php';

/**
 * Кнопка очищення не сміє чіпати чуже: у тій самій логічній DB лежать сесії й
 * черги інших сервісів. Замок перерахунку знімає лише той, хто його взяв.
 */
class FlushScopeAndLockOwnershipTest extends TestCase
{
    use PrivateAccess;

    private function makeService(FakeRedisClient $client, string $prefix = 'okay:'): RedisCacheService
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn (string $k) => [
            'sviat__redis__enabled'     => true,
            'sviat__redis__host'        => '127.0.0.1',
            'sviat__redis__port'        => 6379,
            'sviat__redis__db'          => 0,
            'sviat__redis__prefix'      => $prefix,
            'sviat__redis__default_ttl' => 600,
        ][$k] ?? null);

        $service = new class ($settings, $client) extends RedisCacheService {
            public array $deferred = [];

            public function __construct(Settings $settings, private FakeRedisClient $bare)
            {
                parent::__construct($settings);
            }

            protected function connectBare(bool $applyKeyPrefix = true, bool $persistent = false)
            {
                return $this->bare;
            }

            protected function defer(callable $task): void
            {
                $this->deferred[] = $task;
            }
        };

        $r = new \ReflectionClass(RedisCacheService::class);
        self::accessible($r->getProperty('client'))->setValue($service, $client);
        self::accessible($r->getProperty('initialized'))->setValue($service, true);

        return $service;
    }

    private function lockKey(FakeRedisClient $client): string
    {
        $locks = array_values(array_filter(array_keys($client->store), fn ($k) => str_ends_with((string) $k, ':lock')));
        self::assertCount(1, $locks, 'замок узято');

        return $locks[0];
    }

    private function staleService(FakeRedisClient $client): RedisCacheService
    {
        // Перший виклик кладе конверт, другий після протухання бере замок.
        $service = $this->makeService($client);
        $service->remember('t', [], [], fn () => 'старе', 60);
        foreach ($client->store as $key => $value) {
            $envelope = unserialize($value);
            $envelope['f'] = 1.0;
            $client->store[$key] = serialize($envelope);
        }

        return $this->makeService($client);
    }

    public function testFlushDeletesOnlyModuleKeys(): void
    {
        $this->requirePhpRedis();
        $client = new FakeRedisClient();
        $client->store['helpers:products:abc'] = 'значення';
        $client->store['helpers:ver:pver:1'] = 3;
        $client->foreign['PHPREDIS_SESSION:abc'] = 'сесія';
        $client->foreign['other-app:helpers:x'] = 'чужий кеш';
        $client->foreign['okay:queue:jobs'] = 'черга під тим самим префіксом';

        self::assertTrue($this->makeService($client)->flushAll());

        self::assertSame([], $client->store);
        self::assertCount(3, $client->foreign);
    }

    public function testFlushWalksAllScanPages(): void
    {
        $this->requirePhpRedis();
        $client = new FakeRedisClient();
        $client->scanPageSize = 2;
        for ($i = 0; $i < 7; $i++) {
            $client->store["helpers:k$i"] = 'v';
        }

        self::assertTrue($this->makeService($client)->flushAll());

        self::assertSame([], $client->store);
    }

    /** Символи glob у префіксі інакше розширили б очищення на чужі ключі. */
    public function testGlobCharactersInPrefixAreLiteral(): void
    {
        $this->requirePhpRedis();
        $client = new FakeRedisClient();
        $client->physicalPrefix = 'shop*:';
        $client->store['helpers:own'] = 'своє';
        $client->foreign['shop2:helpers:neighbour'] = 'сусідній магазин';

        self::assertTrue($this->makeService($client, 'shop*:')->flushAll());

        self::assertSame([], $client->store);
        self::assertArrayHasKey('shop2:helpers:neighbour', $client->foreign);
    }

    public function testFailedScanIsReportedNotSilent(): void
    {
        $this->requirePhpRedis();
        $client = new FakeRedisClient();
        $client->failScanTimes = 1;
        $service = $this->makeService($client);

        self::assertFalse($service->flushAll());
        self::assertSame('scan failed', $service->getLastError());
    }

    public function testRefreshReleasesItsOwnLockThroughCompareAndDelete(): void
    {
        $this->requirePhpRedis();
        $client = new FakeRedisClient();
        $service = $this->staleService($client);
        $service->remember('t', [], [], fn () => 'нове', 60);
        $lock = $this->lockKey($client);

        ($service->deferred[0])();

        self::assertArrayNotHasKey($lock, $client->store);
        self::assertSame(['eval', [$lock, $this->tokenFrom($client)]], $this->lastCall($client, 'eval'));
    }

    public function testExpiredOwnerDoesNotReleaseReplacementLock(): void
    {
        $this->requirePhpRedis();
        $client = new FakeRedisClient();
        $service = $this->staleService($client);
        $service->remember('t', [], [], fn () => 'нове', 60);
        $lock = $this->lockKey($client);

        // Замок протух, поки перерахунок тривав, і його взяв інший воркер.
        $client->store[$lock] = 'токен-іншого-воркера';
        ($service->deferred[0])();

        self::assertSame('токен-іншого-воркера', $client->store[$lock]);
    }

    public function testLockTokensDifferBetweenAcquisitions(): void
    {
        $this->requirePhpRedis();
        $tokens = [];
        foreach ([1, 2] as $ignored) {
            $client = new FakeRedisClient();
            $service = $this->staleService($client);
            $service->remember('t', [], [], fn () => 'нове', 60);
            $tokens[] = $client->store[$this->lockKey($client)];
        }

        self::assertNotSame($tokens[0], $tokens[1]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $tokens[0]);
    }

    private function requirePhpRedis(): void
    {
        if (!class_exists('\\Redis')) {
            $this->markTestSkipped('phpredis not installed');
        }
    }

    private function lastCall(FakeRedisClient $client, string $name): ?array
    {
        foreach (array_reverse($client->calls) as $call) {
            if ($call[0] === $name) {
                return $call;
            }
        }

        return null;
    }

    private function tokenFrom(FakeRedisClient $client): string
    {
        foreach ($client->calls as $call) {
            if ($call[0] === 'set' && str_ends_with((string) $call[1], ':lock')) {
                return $call[2];
            }
        }
        self::fail('замок не встановлювали');
    }
}
