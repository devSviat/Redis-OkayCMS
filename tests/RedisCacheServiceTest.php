<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeRedisClient.php';
require_once __DIR__ . '/PrivateAccess.php';

class RedisCacheServiceTest extends TestCase
{
    use PrivateAccess;

    private function makeService(FakeRedisClient $client, bool $enabled = true): RedisCacheService
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(function (string $key) use ($enabled) {
            return [
                'sviat__redis__enabled' => $enabled,
                'sviat__redis__host'    => '127.0.0.1',
                'sviat__redis__port'    => 6379,
                'sviat__redis__db'      => 0,
                'sviat__redis__prefix'  => 'okay:',
                'sviat__redis__default_ttl' => 600,
            ][$key] ?? null;
        });

        $service = new RedisCacheService($settings);
        $r = new \ReflectionClass(RedisCacheService::class);
        self::accessible($r->getProperty('client'))->setValue($service, $client);
        self::accessible($r->getProperty('initialized'))->setValue($service, true);
        return $service;
    }

    public function testBumpInvokesIncrOnTagKey(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client);

        $service->bump('pver:42');

        $this->assertSame(1, $client->store['helpers:ver:pver:42']);
    }

    public function testVersionReturnsZeroWhenAbsent(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client);

        $this->assertSame(0, $service->version('pver:99'));
    }

    public function testVersionReturnsZeroWhenDisabled(): void
    {
        // No phpredis guard — isEnabled() short-circuits before any client call.
        $client = new FakeRedisClient();
        $service = $this->makeService($client, false);

        $this->assertSame(0, $service->version('pver:1'));
    }

    public function testVersionReadsExistingCounter(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $client->store['helpers:ver:pall:global'] = '7';
        $service = $this->makeService($client);

        $this->assertSame(7, $service->version('pall:global'));
    }

    public function testVersionsUsesMGetForBatch(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $client->store['helpers:ver:pver:1'] = '3';
        $client->store['helpers:ver:pall:global'] = '5';
        $service = $this->makeService($client);

        $result = $service->versions(['pver:1', 'pall:global', 'plist:global']);

        $this->assertSame(['pver:1' => 3, 'pall:global' => 5, 'plist:global' => 0], $result);
        $mGetCalls = array_filter($client->calls, fn($c) => $c[0] === 'mGet');
        $this->assertCount(1, $mGetCalls, 'versions() must use a single mGET');
    }

    public function testMakeVersionedKeyDeterministicAndIncludesTagVersions(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $client->store['helpers:ver:pver:42'] = '7';
        $client->store['helpers:ver:pall:global'] = '3';
        $service = $this->makeService($client);

        $k1 = $service->makeVersionedKey('product_attach_data', ['pver:42', 'pall:global'], ['arg1', 2]);
        $k2 = $service->makeVersionedKey('product_attach_data', ['pver:42', 'pall:global'], ['arg1', 2]);

        $this->assertSame($k1, $k2);
        $this->assertStringContainsString(':pv427', $k1, 'must embed pver version');
        $this->assertStringContainsString(':pa3',  $k1, 'must embed pall version');
    }

    public function testBumpClearsVersionMemo(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client);

        $this->assertSame(0, $service->version('pver:1'));
        $service->bump('pver:1');
        $this->assertSame(1, $service->version('pver:1'));
    }

    /**
     * Під maxmemory-policy volatile-lru виселяються лише ключі з TTL. Запис
     * без TTL зробив би кеш невиселюваним і витіснив би лічильники версій.
     */
    public function testSetNeverWritesWithoutTtl(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client);

        $service->set('k', ['a'], 0);

        $this->assertArrayHasKey('k', $client->ttls, 'запис мусить іти через setex');
        $this->assertGreaterThanOrEqual(60, $client->ttls['k']);
        $this->assertSame([], array_filter($client->calls, fn($c) => $c[0] === 'set'));
    }

    public function testSetAppliesTtlFloorToNegativeAndNull(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client);

        $service->set('a', 1, -5);
        $service->set('b', 2, null);

        $this->assertGreaterThanOrEqual(60, $client->ttls['a']);
        $this->assertGreaterThanOrEqual(60, $client->ttls['b']);
    }

    /**
     * Імпорт на 5000 варіантів не має зробити 5000 INCR і 5000 разів
     * обнулити лістинговий кеш.
     */
    public function testBumpOnceCollapsesRepeatedTagsWithinRequest(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client);

        $service->bumpOnce('plist:global');
        $service->bumpOnce('plist:global');
        $service->bumpOnce('plist:global');

        $incrCalls = array_filter($client->calls, fn($c) => $c[0] === 'incr');
        $this->assertCount(1, $incrCalls);
        $this->assertSame(1, $client->store['helpers:ver:plist:global']);
    }

    /**
     * StockSync іде чанками хвилинами в одному процесі. Схлопування на весь
     * процес лишило б вітрину з даними середини імпорту до кінця TTL, тому
     * після вікна bump має відбутися знову.
     */
    public function testBumpOnceBumpsAgainAfterCollapseWindow(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = new class ($this->createStub(Settings::class)) extends RedisCacheService {
            public float $clock = 1000.0;
            public function isEnabled(): bool { return true; }
            protected function now(): float { return $this->clock; }
        };
        $r = new \ReflectionClass(RedisCacheService::class);
        self::accessible($r->getProperty('client'))->setValue($service, $client);
        self::accessible($r->getProperty('initialized'))->setValue($service, true);

        $service->bumpOnce('plist:global');
        $service->clock += 0.2;
        $service->bumpOnce('plist:global');   // у вікні — схлопується
        $service->clock += 5.0;
        $service->bumpOnce('plist:global');   // вікно минуло — бампаємо знову

        $this->assertCount(2, array_filter($client->calls, fn($c) => $c[0] === 'incr'));
        $this->assertSame(2, $client->store['helpers:ver:plist:global']);
    }

    /**
     * Хвіст серії. Вікно схлопує повтори, і останній із них губився: якщо після
     * нього bump більше не приходить, версія лишається на значенні початку
     * серії. Останній чанк імпорту вітрина не бачила до кінця TTL.
     */
    public function testSwallowedBumpIsFlushedWhenTheSeriesEnds(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeCollapsingService($client);

        $service->bumpOnce('plist:global');   // t=0, піднімає
        $service->clock += 0.3;
        $service->bumpOnce('plist:global');   // у вікні — відкладається
        $service->clock += 0.3;
        $service->bumpOnce('plist:global');   // теж у вікні

        $this->assertCount(1, array_filter($client->calls, fn($c) => $c[0] === 'incr'), 'у вікні bump один');
        $this->assertSame(1, $service->flushRegistrations, 'хук реєструється один раз на серію');

        $service->flushPendingBumps();        // процес завершується

        $this->assertCount(2, array_filter($client->calls, fn($c) => $c[0] === 'incr'), 'хвіст серії піднімає версію');
        $this->assertSame(2, $client->store['helpers:ver:plist:global']);
    }

    /** Успішний bump знімає борг — на завершенні процесу зайвого INCR не буде. */
    public function testFlushDoesNothingWhenTheWindowAlreadyExpired(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeCollapsingService($client);

        $service->bumpOnce('plist:global');
        $service->clock += 0.3;
        $service->bumpOnce('plist:global');   // відкладено
        $service->clock += 5.0;
        $service->bumpOnce('plist:global');   // вікно минуло — борг погашено

        $service->flushPendingBumps();

        $this->assertCount(2, array_filter($client->calls, fn($c) => $c[0] === 'incr'));
        $this->assertSame(2, $client->store['helpers:ver:plist:global']);
    }

    /** Сервіс із керованим годинником і без справжнього shutdown-хука. */
    private function makeCollapsingService(FakeRedisClient $client): RedisCacheService
    {
        $service = new class ($this->createStub(Settings::class)) extends RedisCacheService {
            public float $clock = 1000.0;
            public int $flushRegistrations = 0;
            public function isEnabled(): bool { return true; }
            protected function now(): float { return $this->clock; }
            protected function registerPendingFlush(): void { $this->flushRegistrations++; }
        };
        $r = new \ReflectionClass(RedisCacheService::class);
        self::accessible($r->getProperty('client'))->setValue($service, $client);
        self::accessible($r->getProperty('initialized'))->setValue($service, true);

        return $service;
    }

    /**
     * Проковтнута помилка INCR не має назавжди прибрати наступні bump цього
     * тега — інакше версія не зростає й лістинг віддає доредакційні дані.
     */
    public function testFailedBumpDoesNotSuppressLaterBumps(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $client->failIncrTimes = 1;
        $service = $this->makeService($client);

        $service->bumpOnce('plist:global');   // падає

        $flags = self::accessible((new \ReflectionClass(RedisCacheService::class))->getProperty('bumpedTags'));
        $this->assertSame(
            [],
            $flags->getValue($service),
            'невдалий bump не має позначати тег як уже піднятий'
        );

        // Після відновлення з'єднання наступна спроба мусить пройти.
        $r = new \ReflectionClass(RedisCacheService::class);
        self::accessible($r->getProperty('client'))->setValue($service, $client);
        self::accessible($r->getProperty('initialized'))->setValue($service, true);

        $service->bumpOnce('plist:global');

        $this->assertSame(1, $client->store['helpers:ver:plist:global'] ?? 0);
    }

    /**
     * Після помилки стан persistent-сокета невідомий: непрочитана відповідь
     * лишилась би в буфері й наступний get() прочитав би її як свою.
     */
    public function testClientIsDiscardedAfterError(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $client->failIncrTimes = 1;
        $service = $this->makeService($client);

        $service->bump('pver:1');

        $prop = self::accessible((new \ReflectionClass(RedisCacheService::class))->getProperty('client'));
        $this->assertNull($prop->getValue($service), 'зіпсоване з\'єднання не можна переюзувати');
        $this->assertNotEmpty(array_filter($client->calls, fn($c) => $c[0] === 'close'));
    }

    public function testExplicitShortTtlIsHonoured(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client);

        $service->set('k', 1, 30);

        $this->assertSame(30, $client->ttls['k'], 'налаштований адміном TTL не має мовчки підніматись');
    }

    public function testBumpOnceStillSeparatesDistinctTags(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client);

        $service->bumpOnce('pver:1');
        $service->bumpOnce('pver:2');
        $service->bumpOnce('pver:1');

        $this->assertCount(2, array_filter($client->calls, fn($c) => $c[0] === 'incr'));
    }

    public function testRequestStatsCountHitsAndMisses(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $client->store['present'] = serialize(['x']);
        $service = $this->makeService($client);

        $service->get('present');
        $service->get('absent');
        $service->get('absent2');

        $stats = $service->getRequestStats();
        $this->assertSame(1, $stats['hits']);
        $this->assertSame(2, $stats['misses']);
        $this->assertArrayHasKey('ms', $stats);
    }

    public function testRequestStatsDoNotDoubleCountMemoizedReads(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $client->store['present'] = serialize(['x']);
        $service = $this->makeService($client);

        $service->get('present');
        $service->get('present');

        $this->assertSame(1, $service->getRequestStats()['hits']);
    }
}
