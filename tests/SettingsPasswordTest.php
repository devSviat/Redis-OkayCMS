<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Design;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Backend\Controllers\RedisSettingsAdmin;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PrivateAccess.php';

/**
 * Пароль Redis не повертається в HTML, тож порожнє поле форми не може означати
 * «стерти»: кожне збереження налаштувань інакше скидало б його.
 */
class SettingsPasswordTest extends TestCase
{
    use PrivateAccess;

    private const string TEMPLATE = __DIR__ . '/../../../../Okay/Modules/Sviat/Redis/Backend/design/html/redis_settings.tpl';

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} збережене й передане в шаблон */
    private function submit(array $post, ?string $stored): array
    {
        $saved = ['sviat__redis__password' => $stored, 'sviat__redis__host' => 'redis.local', 'sviat__redis__port' => 6379];
        $post += ['host' => 'redis.local', 'port' => '6379', 'prefix' => 'okay:'];

        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(function (string $k) use (&$saved) {
            return $saved[$k] ?? null;
        });
        $settings->method('set')->willReturnCallback(function (string $k, $v) use (&$saved) {
            $saved[$k] = $v;
        });

        $request = new class ($post) extends Request {
            public function __construct(private array $form)
            {
            }

            public function method($method = null)
            {
                return $method === null ? 'POST' : strtolower((string) $method) === 'post';
            }

            public function post($name = null, $type = null, $default = null)
            {
                return $this->form[$name] ?? null;
            }
        };

        $assigned = [];
        $design = $this->createStub(Design::class);
        $design->method('assign')->willReturnCallback(function ($k, $v) use (&$assigned) {
            $assigned[$k] = $v;
        });
        $design->method('fetch')->willReturn('');

        $controller = new \ReflectionClass(new class extends RedisSettingsAdmin {
            public function __construct() {}
            protected function isFromThisSite(): bool { return true; }
        })->newInstanceWithoutConstructor();
        $parent = new \ReflectionClass(\Okay\Admin\Controllers\IndexAdmin::class);
        foreach (['settings' => $settings, 'request' => $request, 'design' => $design, 'response' => $this->createStub(Response::class)] as $prop => $value) {
            self::accessible($parent->getProperty($prop))->setValue($controller, $value);
        }

        $redis = $this->createStub(RedisCacheService::class);
        $redis->method('connectionView')->willReturn(['enabled' => false, 'host' => 'redis.local', 'port' => 6379, 'db' => 0, 'username' => '', 'prefix' => 'okay:']);
        $controller->fetch($redis);

        return [$saved, $assigned];
    }

    public function testBlankFieldKeepsStoredPassword(): void
    {
        [$saved] = $this->submit(['action' => 'save', 'password' => ''], 'секрет');

        self::assertSame('секрет', $saved['sviat__redis__password']);
    }

    public function testTypedPasswordReplacesStoredOne(): void
    {
        [$saved] = $this->submit(['action' => 'save', 'password' => 'новий'], 'секрет');

        self::assertSame('новий', $saved['sviat__redis__password']);
    }

    public function testClearCheckboxRemovesStoredPassword(): void
    {
        [$saved] = $this->submit(['action' => 'save', 'password' => '', 'clear_password' => '1'], 'секрет');

        self::assertSame('', $saved['sviat__redis__password']);
    }

    public function testTypedPasswordWinsOverClearCheckbox(): void
    {
        [$saved] = $this->submit(['action' => 'save', 'password' => 'новий', 'clear_password' => '1'], 'секрет');

        self::assertSame('новий', $saved['sviat__redis__password'], 'введений пароль не сміє мовчки зникнути');
    }

    public function testPlaceholderDependsOnWhetherPasswordIsStored(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);

        self::assertMatchesRegularExpression(
            '/placeholder="\\{if \\$redis_password_is_set\\}\\{\\$btr->sviat_redis_password_placeholder\\|escape\\}\\{else\\}\\{\\$btr->sviat_redis_password_placeholder_empty\\|escape\\}\\{\\/if\\}"/',
            $template
        );
    }

    public function testPasswordKeysExistInEveryLanguage(): void
    {
        foreach (['ua', 'en', 'ru'] as $language) {
            $lang = [];
            require __DIR__ . "/../../../../Okay/Modules/Sviat/Redis/Backend/lang/$language.php";

            foreach (['sviat_redis_password_placeholder', 'sviat_redis_password_placeholder_empty', 'sviat_redis_clear_password'] as $key) {
                self::assertNotEmpty($lang[$key] ?? null, "$language: $key");
            }
        }
    }

    public function testForeignOriginWritesNothing(): void
    {
        $saved = [];
        $settings = $this->createStub(Settings::class);
        $settings->method('set')->willReturnCallback(function (string $k, $v) use (&$saved) {
            $saved[$k] = $v;
        });
        $request = new class extends Request {
            public function __construct() {}
            public function method($method = null) { return true; }
            public function post($name = null, $type = null, $default = null) { return $name === 'action' ? 'save' : 'x'; }
        };
        $statuses = [];
        $response = $this->createStub(Response::class);
        $response->method('setStatusCode')->willReturnCallback(function ($code) use (&$statuses, $response) {
            $statuses[] = $code;
            return $response;
        });

        $controller = new \ReflectionClass(new class extends RedisSettingsAdmin {
            public function __construct() {}
            protected function isFromThisSite(): bool { return false; }
        })->newInstanceWithoutConstructor();
        $parent = new \ReflectionClass(\Okay\Admin\Controllers\IndexAdmin::class);
        foreach (['settings' => $settings, 'request' => $request, 'design' => $this->createStub(Design::class), 'response' => $response] as $prop => $value) {
            self::accessible($parent->getProperty($prop))->setValue($controller, $value);
        }

        $controller->fetch($this->createStub(RedisCacheService::class));

        self::assertSame([], $saved);
        self::assertSame([403], $statuses);
    }

    /** Форма очищення не несе полів налаштувань: збереження вимкнуло б кеш і скинуло TTL. */
    public function testFlushChangesNoSettings(): void
    {
        [$saved] = $this->submit(['action' => 'flush_helpers'], 'секрет');

        self::assertSame('секрет', $saved['sviat__redis__password']);
        self::assertArrayNotHasKey('sviat__redis__enabled', $saved);
        self::assertArrayNotHasKey('sviat__redis__default_ttl', $saved);
    }

    public function testPostWithoutKnownActionWritesNothing(): void
    {
        [$saved] = $this->submit(['password' => 'новий'], 'секрет');

        self::assertSame('секрет', $saved['sviat__redis__password']);
        self::assertArrayNotHasKey('sviat__redis__default_ttl', $saved);
    }

    public function testStoredPasswordNeverReachesTemplate(): void
    {
        [, $assigned] = $this->submit(['action' => 'save', 'password' => ''], 'секрет');

        self::assertArrayNotHasKey('redis_password', $assigned);
        self::assertNotContains('секрет', $assigned);
        self::assertTrue($assigned['redis_password_is_set']);
    }

    public function testClearCheckboxOnlyOfferedWhenThereIsSomethingToClear(): void
    {
        [, $assigned] = $this->submit(['action' => 'save', 'password' => ''], '');

        self::assertFalse($assigned['redis_password_is_set']);
    }

    public function testTemplateRendersPasswordInputEmpty(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);

        self::assertStringContainsString('name="password" value=""', $template);
        self::assertStringNotContainsString('$redis_password|', $template);
    }
}
