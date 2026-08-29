<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeRedisClient.php';
require_once __DIR__ . '/PrivateAccess.php';

/**
 * remember() віддає протухле значення одразу, а перерахунок відкладає. Помилка
 * тут не видима на вітрині: сторінка лишається правильною, просто хтось із
 * відвідувачів раз на TTL чекає повний перерахунок.
 */
class RememberSwrTest extends TestCase
{
    use PrivateAccess;

    private function makeService(FakeRedisClient $client, float $now, bool $enabled = true): RedisCacheService
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn (string $k) => [
            'sviat__redis__enabled'     => $enabled,
            'sviat__redis__host'        => '127.0.0.1',
            'sviat__redis__port'        => 6379,
            'sviat__redis__db'          => 0,
            'sviat__redis__prefix'      => 'okay:',
            'sviat__redis__default_ttl' => 600,
        ][$k] ?? null);

        $service = new class ($settings, $now) extends RedisCacheService {
            public array $deferred = [];

            /** @var float */
            private $fakeNow;

            public function __construct(Settings $settings, float $fakeNow)
            {
                parent::__construct($settings);
                $this->fakeNow = $fakeNow;
            }

            protected function now(): float
            {
                return $this->fakeNow;
            }

            /** Замість register_shutdown_function — щоб тест міг запустити вручну. */
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

    public function testMissCallsProducerAndStoresEnvelope(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client, 1000.0);

        $calls = 0;
        $value = $service->remember('t', [], [], function () use (&$calls) { $calls++; return 'свіже'; }, 600);

        self::assertSame('свіже', $value);
        self::assertSame(1, $calls);

        $stored = unserialize(array_values($client->store)[0]);
        self::assertSame(1, $stored['__swr']);
        self::assertSame(1600.0, $stored['f'], 'свіжість = зараз + ttl');
    }

    /**
     * Ключ мусить пережити власну свіжість із запасом. Інакше Redis виселить
     * значення рівно в мить протухання, віддавати стане нічого — і вся фіча
     * тихо звужується до звичайного кешу з твердим протуханням.
     */
    /**
     * Порожній результат до кешу не потрапляє: у пошуку кожна фраза дає свій
     * ключ, а користі з такого запису немає — розширення однаково перезапитує.
     */
    public function testResultTheCallerRejectsIsNotStored(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client, 1000.0);

        $value = $service->remember(
            't',
            [],
            [],
            static fn() => [],
            600,
            null,
            static fn($result) => !empty($result)
        );

        self::assertSame([], $value, 'викликач усе одно отримує свій результат');
        self::assertSame([], $client->store, 'а в кеші порожнього запису немає');
    }

    public function testAcceptedResultIsStoredAsUsual(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client, 1000.0);

        $service->remember(
            't',
            [],
            [],
            static fn() => ['щось'],
            600,
            null,
            static fn($result) => !empty($result)
        );

        self::assertCount(1, $client->store);
    }

    public function testKeyOutlivesItsFreshness(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client, 1000.0);

        $service->remember('t', [], [], fn () => 'свіже', 600);

        $key = array_key_first($client->store);
        $freshFor = unserialize($client->store[$key])['f'] - 1000.0;
        self::assertGreaterThan($freshFor, $client->ttls[$key], 'протухле значення має чим відповідати');
        self::assertSame(600 + 3600, $client->ttls[$key], 'ttl + SWR_GRACE');
    }

    public function testFreshHitSkipsProducerAndRunsOnHit(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client, 1000.0);
        $service->remember('t', [], [], fn () => 'перше', 600);

        $calls = 0;
        $value = $service->remember('t', [], [], function () use (&$calls) { $calls++; return 'друге'; }, 600,
            fn ($v) => $v . '+розширення');

        self::assertSame('перше+розширення', $value);
        self::assertSame(0, $calls, 'свіже значення не перераховують');
    }

    public function testStaleHitReturnsOldValueAndDefersRefresh(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $warm = $this->makeService($client, 1000.0);
        $warm->remember('t', [], [], fn () => 'старе', 600);

        $service = $this->makeService($client, 2000.0);   // TTL уже минув
        $calls = 0;
        $value = $service->remember('t', [], [], function () use (&$calls) { $calls++; return 'нове'; }, 600);

        self::assertSame('старе', $value, 'відвідувач не чекає перерахунку');
        self::assertSame(0, $calls, 'перерахунок не виконується в запиті');
        self::assertCount(1, $service->deferred, 'перерахунок заплановано');

        ($service->deferred[0])();
        self::assertSame(1, $calls);
        $stored = unserialize($client->store[array_key_first($client->store)]);
        self::assertSame('нове', $stored['v']);
    }

    public function testSecondRequestDoesNotDuplicateRefresh(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $this->makeService($client, 1000.0)->remember('t', [], [], fn () => 'старе', 600);

        $first  = $this->makeService($client, 2000.0);
        $second = $this->makeService($client, 2000.0);
        $first->remember('t', [], [], fn () => 'нове', 600);
        $second->remember('t', [], [], fn () => 'нове', 600);

        self::assertCount(1, $first->deferred);
        self::assertCount(0, $second->deferred, 'замок не пускає другий перерахунок');
    }

    /**
     * Той самий ключ читають кілька разів за запит. Замок у Redis тут не
     * допоможе: його вже взяв цей самий процес, тож без внутрішньої позначки
     * кожне наступне читання знову ходило б у Redis по нього.
     */
    public function testRepeatedReadsInOneRequestScheduleOneRefresh(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $this->makeService($client, 1000.0)->remember('t', [], [], fn () => 'старе', 600);

        $service = $this->makeService($client, 2000.0);
        $service->remember('t', [], [], fn () => 'нове', 600);
        $before = count(array_filter($client->calls, static fn($c) => $c[0] === 'set'));
        $service->remember('t', [], [], fn () => 'нове', 600);
        $after = count(array_filter($client->calls, static fn($c) => $c[0] === 'set'));

        self::assertCount(1, $service->deferred, 'перерахунок планується один раз на запит');
        self::assertSame($before, $after, 'і другого походу за замком у Redis теж не має бути');
    }

    /**
     * Найдорожча помилка тут — не промах кешу, а відкат модуля: старий код читає
     * голе значення й на конверті падає TypeError уже у викликача. Тому конверти
     * лежать під власним іменем ключа, і старий код їх просто не бачить.
     */
    public function testEnvelopeDoesNotOccupyThePlainKey(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client, 1000.0);

        $service->remember('catalog_features', [], [], fn () => ['значення'], 600);

        $plainKey = $service->makeVersionedKey('catalog_features', [], []);
        self::assertArrayNotHasKey($plainKey, $client->store, 'старий код не має бачити конверт');
        self::assertCount(1, $client->store);
        self::assertStringContainsString(':swr', array_key_first($client->store));
    }

    public function testRefreshReleasesTheLock(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $this->makeService($client, 1000.0)->remember('t', [], [], fn () => 'старе', 600);

        $service = $this->makeService($client, 2000.0);
        $service->remember('t', [], [], fn () => 'нове', 600);

        $lockKeys = array_filter(array_keys($client->store), fn ($k) => str_ends_with($k, ':lock'));
        self::assertCount(1, $lockKeys, 'замок узято');

        ($service->deferred[0])();

        $lockKeys = array_filter(array_keys($client->store), fn ($k) => str_ends_with($k, ':lock'));
        self::assertSame([], $lockKeys, 'без зняття налаштований TTL менший за SWR_LOCK_TTL не діяв би');
    }

    public function testFailedRefreshKeepsTheLock(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $this->makeService($client, 1000.0)->remember('t', [], [], fn () => 'старе', 600);

        $service = $this->makeService($client, 2000.0);
        $service->remember('t', [], [], function () { throw new \RuntimeException('база лягла'); }, 600);
        ($service->deferred[0])();

        $lockKeys = array_filter(array_keys($client->store), fn ($k) => str_ends_with($k, ':lock'));
        self::assertCount(1, $lockKeys, 'після збою замок тримає паузу до наступної спроби');
        self::assertSame('старе', $service->remember('t', [], [], fn () => 'нове', 600));
    }

    /**
     * Значення старого формату лежить під іншим іменем ключа, тож remember()
     * його не читає — це промах, а не влучання. Головне, що воно лишається
     * недоторканим: після відкату старий код знайде свій запис на місці.
     */
    public function testPlainKeyValueIsNeitherReadNorOverwritten(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client, 1000.0);
        $plainKey = $service->makeVersionedKey('t', [], []);
        $client->store[$plainKey] = serialize('значення з попереднього релізу');

        $calls = 0;
        $value = $service->remember('t', [], [], function () use (&$calls) { $calls++; return 'нове'; }, 600);

        self::assertSame('нове', $value);
        self::assertSame(1, $calls, 'чужий формат — це промах');
        self::assertSame(
            'значення з попереднього релізу',
            unserialize($client->store[$plainKey]),
            'запис старого коду лишається цілим на випадок відкату'
        );
    }

    public function testDisabledCacheJustCallsProducer(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }
        $client = new FakeRedisClient();
        $service = $this->makeService($client, 1000.0, false);

        self::assertSame('без кешу', $service->remember('t', [], [], fn () => 'без кешу', 600));
        self::assertSame([], $client->store);
    }
}
