<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeRedisClient.php';

class RedisCacheServiceTest extends TestCase
{
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
        $r = new \ReflectionClass($service);
        $reflected = $r->getProperty('client');
        if (PHP_VERSION_ID < 80100) { $reflected->setAccessible(true); }
        $reflected->setValue($service, $client);
        $reflected = $r->getProperty('initialized');
        if (PHP_VERSION_ID < 80100) { $reflected->setAccessible(true); }
        $reflected->setValue($service, true);
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
}
