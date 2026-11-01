<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Config;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeRedisClient.php';
require_once __DIR__ . '/PrivateAccess.php';

/** Хто може писати в Redis, не має змінювати ціни й HTML вітрини. */
class CacheSigningTest extends TestCase
{
    use PrivateAccess;

    private array $envBackup = [];

    protected function setUp(): void
    {
        if (!class_exists('\\Redis')) {
            $this->markTestSkipped('phpredis not installed');
        }
        foreach (['REDIS_SIGNING_KEY', 'REDIS_ENABLED'] as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }

    private function service(FakeRedisClient $client, array $config = ['db_password' => 'secret', 'db_name' => 'shop']): RedisCacheService
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn (string $k) => ['sviat__redis__enabled' => true, 'sviat__redis__default_ttl' => 600][$k] ?? null);
        $configStub = $this->createStub(Config::class);
        $configStub->method('get')->willReturnCallback(fn (string $k) => $config[$k] ?? null);

        $service = new RedisCacheService($settings, $configStub);
        $r = new \ReflectionClass(RedisCacheService::class);
        self::accessible($r->getProperty('client'))->setValue($service, $client);
        self::accessible($r->getProperty('initialized'))->setValue($service, true);

        return $service;
    }

    public function testValueSurvivesRoundTrip(): void
    {
        $client = new FakeRedisClient();
        $this->service($client)->set('helpers:k', ['price' => 100]);

        self::assertSame(['price' => 100], $this->service($client)->get('helpers:k'));
        self::assertStringStartsWith('sig2:', $client->store['helpers:k']);
    }

    public function testTamperedValueIsAMiss(): void
    {
        $client = new FakeRedisClient();
        $this->service($client)->set('helpers:k', ['price' => 100]);
        $client->store['helpers:k'] = str_replace('i:100', 'i:001', $client->store['helpers:k']);

        self::assertNull($this->service($client)->get('helpers:k'));
    }

    public function testUnsignedValueWrittenDirectlyIsAMiss(): void
    {
        $client = new FakeRedisClient();
        $client->store['helpers:k'] = serialize(['price' => 1]);

        $service = $this->service($client);

        self::assertNull($service->get('helpers:k'));
        self::assertSame(['helpers:k' => null], $service->mGet(['helpers:k']));
    }

    public function testValueSignedWithAnotherKeyIsAMiss(): void
    {
        $client = new FakeRedisClient();
        $this->service($client, ['db_password' => 'other'])->set('helpers:k', ['price' => 1]);

        self::assertNull($this->service($client)->get('helpers:k'));
    }

    public function testSignedValueCannotBeMovedToAnotherKey(): void
    {
        $client = new FakeRedisClient();
        $this->service($client)->set('helpers:cheap', ['price' => 1]);
        $client->store['helpers:expensive'] = $client->store['helpers:cheap'];

        self::assertNull($this->service($client)->get('helpers:expensive'));
        self::assertSame(['helpers:expensive' => null], $this->service($client)->mGet(['helpers:expensive']));
    }

    public function testEnvKeyWins(): void
    {
        $client = new FakeRedisClient();
        putenv('REDIS_SIGNING_KEY=explicit');
        $this->service($client, ['db_password' => 'a'])->set('helpers:k', 1);

        self::assertSame(1, $this->service($client, ['db_password' => 'b'])->get('helpers:k'));
    }

    public function testMGetVerifiesEachValue(): void
    {
        $client = new FakeRedisClient();
        $this->service($client)->set('helpers:good', 'ok');
        $client->store['helpers:bad'] = 'sig2:' . str_repeat('0', 64) . serialize('evil');

        $result = $this->service($client)->mGet(['helpers:good', 'helpers:bad']);

        self::assertSame(['helpers:good' => 'ok', 'helpers:bad' => null], $result);
    }

    public function testKeyIsNeverStoredInRedis(): void
    {
        $client = new FakeRedisClient();
        $this->service($client)->set('helpers:k', 'x');

        self::assertStringNotContainsString('secret', implode('', array_map('strval', $client->store)));
    }

    public function testServiceReceivesConfigInProduction(): void
    {
        $services = (string) file_get_contents(__DIR__ . '/../../../../Okay/Modules/Sviat/Redis/Init/services.php');

        self::assertStringContainsString('[new SR(Settings::class), new SR(Config::class)]', $services);
    }
}
