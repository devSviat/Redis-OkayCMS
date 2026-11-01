<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Config;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use Okay\Modules\Sviat\Redis\Services\RedisConnectionForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Підключення можна задати в env, config.local.php або адмінці. Те, що задано
 * поза адмінкою, менеджер змінити не може, бо зміна хоста віддала б пароль.
 */
class ConnectionSourcesTest extends TestCase
{
    private const array ENV = ['REDIS_ENABLED', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_DB', 'REDIS_USERNAME', 'REDIS_PASSWORD', 'REDIS_PREFIX'];

    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }

    private function service(array $config = [], array $settings = []): RedisCacheService
    {
        $settingsStub = $this->createStub(Settings::class);
        $settingsStub->method('get')->willReturnCallback(fn (string $k) => $settings[$k] ?? null);
        $configStub = $this->createStub(Config::class);
        $configStub->method('get')->willReturnCallback(fn (string $k) => $config[$k] ?? null);

        return new RedisCacheService($settingsStub, $configStub);
    }

    public function testAdminSettingsAreUsedWhenNothingElseIsSet(): void
    {
        $service = $this->service([], ['sviat__redis__host' => 'admin.host', 'sviat__redis__port' => 6390]);

        self::assertSame('admin.host', $service->connectionView()['host']);
        self::assertSame(6390, $service->connectionView()['port']);
        self::assertSame([], $service->lockedConnectionFields());
    }

    public function testConfigFileBeatsAdminSettings(): void
    {
        $service = $this->service(
            ['redis_host' => 'config.host', 'redis_port' => '6381', 'redis_db' => '0', 'redis_prefix' => 'shop:'],
            ['sviat__redis__host' => 'admin.host', 'sviat__redis__port' => 6390, 'sviat__redis__db' => 3]
        );

        $view = $service->connectionView();

        self::assertSame('config.host', $view['host']);
        self::assertSame(6381, $view['port']);
        self::assertSame(0, $view['db'], 'нуль у конфігу не губиться на користь адмінки');
        self::assertSame('shop:', $view['prefix']);
    }

    public function testEnvBeatsConfigFile(): void
    {
        putenv('REDIS_HOST=env.host');

        $service = $this->service(['redis_host' => 'config.host']);

        self::assertSame('env.host', $service->connectionView()['host']);
        self::assertSame(['host' => 'env'], $service->lockedConnectionFields());
    }

    public function testEmptyConfigValueDoesNotLock(): void
    {
        self::assertSame([], $this->service(['redis_host' => '  '])->lockedConnectionFields());
    }

    public function testEnabledZeroInConfigDisablesCache(): void
    {
        $service = $this->service(['redis_enabled' => '0'], ['sviat__redis__enabled' => 1]);

        self::assertFalse($service->connectionView()['enabled']);
    }

    /** Пароль, заданий поза адмінкою, не має піти на хост, який обрав менеджер. */
    public function testFixedPasswordLocksItsTarget(): void
    {
        $locked = $this->service(['redis_password' => 'секрет'])->lockedConnectionFields();

        ksort($locked);
        self::assertSame(['host' => 'config', 'password' => 'config', 'port' => 'config', 'username' => 'config'], $locked);
    }

    public function testViewNeverCarriesThePassword(): void
    {
        $view = $this->service(['redis_password' => 'секрет'])->connectionView();

        self::assertArrayNotHasKey('auth', $view);
        self::assertNotContains('секрет', $view);
    }

    private function post(array $overrides = []): array
    {
        return $overrides + ['host' => 'redis.local', 'port' => '6379', 'db' => '0', 'username' => '', 'password' => '', 'prefix' => 'okay:'];
    }

    private function stored(): array
    {
        return ['host' => 'redis.local', 'port' => '6379', 'username' => '', 'password' => 'секрет'];
    }

    public function testChangingHostDropsStoredPassword(): void
    {
        $result = RedisConnectionForm::apply($this->post(['host' => 'attacker.example']), $this->stored());

        self::assertSame('', $result['values']['password']);
        self::assertTrue($result['passwordDropped']);
    }

    #[DataProvider('targetChanges')]
    public function testAnyTargetChangeDropsPassword(array $change): void
    {
        self::assertTrue(RedisConnectionForm::apply($this->post($change), $this->stored())['passwordDropped']);
    }

    public static function targetChanges(): array
    {
        return [
            'port'     => [['port' => '6380']],
            'username' => [['username' => 'other']],
        ];
    }

    public function testUnchangedTargetKeepsPassword(): void
    {
        $result = RedisConnectionForm::apply($this->post(), $this->stored());

        self::assertArrayNotHasKey('password', $result['values']);
        self::assertFalse($result['passwordDropped']);
    }

    public function testNewPasswordOnNewHostIsAccepted(): void
    {
        $result = RedisConnectionForm::apply($this->post(['host' => 'new.host', 'password' => 'новий']), $this->stored());

        self::assertSame('новий', $result['values']['password']);
        self::assertFalse($result['passwordDropped']);
    }

    public function testLockedFieldsAreNotChanged(): void
    {
        $result = RedisConnectionForm::apply(
            $this->post(['host' => 'attacker.example', 'password' => 'x']),
            $this->stored(),
            ['host' => 'config', 'password' => 'config']
        );

        self::assertArrayNotHasKey('host', $result['values']);
        self::assertArrayNotHasKey('password', $result['values']);
    }

    #[DataProvider('hosts')]
    public function testHostValidation(string $host, bool $valid): void
    {
        self::assertSame($valid, RedisConnectionForm::isValidHost($host));
    }

    public static function hosts(): array
    {
        return [
            'name'        => ['redis.internal', true],
            'ipv4'        => ['127.0.0.1', true],
            'ipv6'        => ['[::1]', true],
            'tls'         => ['tls://redis.example', true],
            'socket'      => ['/var/run/redis/redis.sock', true],
            'empty'       => ['', false],
            'http'        => ['http://internal/', false],
            'gopher'      => ['gopher://x', false],
            'space'       => ['a b', false],
            'newline'     => ["a\nb", false],
            'serialized'  => ['O:8:"stdClass":0:{}', false],
            'traversal'   => ['user@host', false],
        ];
    }

    #[DataProvider('badPorts')]
    public function testPortOutOfRangeIsRefusedAndNotSaved(string $port): void
    {
        $result = RedisConnectionForm::apply($this->post(['port' => $port]), $this->stored());

        self::assertContains('port', $result['errors']);
        self::assertArrayNotHasKey('port', $result['values']);
    }

    public static function badPorts(): array
    {
        return [['0'], ['-1'], ['65536'], ['abc']];
    }

    public function testSerialisedValuesAreNotSaved(): void
    {
        $result = RedisConnectionForm::apply(
            $this->post(['prefix' => 'O:8:"stdClass":0:{}', 'password' => 'a:0:{}', 'username' => 'b:0;']),
            $this->stored()
        );

        self::assertSame(['username', 'prefix', 'password'], $result['errors']);
        self::assertArrayNotHasKey('prefix', $result['values']);
        self::assertArrayNotHasKey('password', $result['values']);
    }

    public function testMoneyHelperIsNotOverridden(): void
    {
        $services = (string) file_get_contents(__DIR__ . '/../../../../Okay/Modules/Sviat/Redis/Init/services.php');

        self::assertStringNotContainsString('RedisMoneyHelper', $services, 'курси валют ідуть у ціну кошика, у кеші їм не місце');
    }
}
