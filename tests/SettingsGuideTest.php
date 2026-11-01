<?php

namespace Modules\Sviat\Redis;

use PHPUnit\Framework\TestCase;

/**
 * Довідка на сторінці налаштувань: користувач копіює звідси конфіг, тож приклад
 * не сміє розійтись із ключами, які модуль справді читає.
 */
class SettingsGuideTest extends TestCase
{
    private const string MODULE = __DIR__ . '/../../../../Okay/Modules/Sviat/Redis';
    private const array KEYS = [
        'sviat_redis_guide_title',
        'sviat_redis_guide_about',
        'sviat_redis_guide_step_connect',
        'sviat_redis_guide_step_test',
        'sviat_redis_guide_step_ttl',
        'sviat_redis_guide_config_title',
        'sviat_redis_guide_config_text',
        'sviat_redis_guide_config_note',
    ];

    private function template(): string
    {
        return (string) file_get_contents(self::MODULE . '/Backend/design/html/redis_settings.tpl');
    }

    public function testEachTaskHasItsOwnTab(): void
    {
        $template = $this->template();

        foreach (['redis_connection', 'redis_ttl', 'redis_guide'] as $tab) {
            self::assertStringContainsString('<a href="#' . $tab . '" class="heading_box tab_navigation_link">', $template);
            self::assertStringContainsString('<div id="' . $tab . '" class="tab">', $template);
        }
        self::assertLessThan(strpos($template, 'id="redis_ttl"'), strpos($template, 'name="host"'));
        self::assertGreaterThan(strpos($template, 'id="redis_ttl"'), strpos($template, 'name="default_ttl"'));
        self::assertGreaterThan(strpos($template, 'id="redis_guide"'), strpos($template, '<pre'));
    }

    /** Кнопки лежать поза вкладками, бо зберігають поля всіх вкладок, а ядро ховає вміст неактивних. */
    public function testActionsStayVisibleOnEveryTab(): void
    {
        $template = $this->template();

        self::assertGreaterThan(strrpos($template, 'class="tab"'), strpos($template, 'value="save"'));
        self::assertLessThan(strrpos($template, '</form>'), strpos($template, 'value="save"'));
    }

    public function testTabsSurviveSaveThroughCurrentUrl(): void
    {
        self::assertSame(1, preg_match('/<form method="post">\s*<input type="hidden" name="session_id"[^>]*>\s*<div class="boxed[^"]*tabs">/', $this->template()));
    }

    public function testTechnicalNamesStayOutOfTheVisibleLabels(): void
    {
        self::assertSame(0, preg_match('/<small>/', $this->template()));
        self::assertStringContainsString('title="CatalogHelper::getCatalogFeatures"', $this->template());
    }

    public function testNothingIsHiddenBehindADisclosure(): void
    {
        self::assertStringNotContainsString('<details', $this->template());
    }

    public function testEveryGuideKeyIsUsedAndTranslated(): void
    {
        foreach (self::KEYS as $key) {
            self::assertStringContainsString('$btr->' . $key . '|escape', $this->template());
        }

        foreach (['ua', 'en', 'ru'] as $language) {
            $lang = [];
            require self::MODULE . "/Backend/lang/$language.php";

            foreach (self::KEYS as $key) {
                self::assertNotEmpty($lang[$key] ?? null, "$language: $key");
            }
            self::assertStringContainsString('config/config.local.php', $lang['sviat_redis_guide_config_text'], $language);
        }
    }

    public function testConfigExampleUsesOnlyKeysTheModuleReads(): void
    {
        self::assertSame(1, preg_match('#<pre[^>]*>(.*?)</pre>#s', $this->template(), $example));
        self::assertStringContainsString('[redis]', $example[1]);
        self::assertSame(6, preg_match_all('/^redis_(\w+) = /m', $example[1], $keys));

        $service = (string) file_get_contents(self::MODULE . '/Services/RedisCacheService.php');
        preg_match_all("/fixedValue\\('(\\w+)'\\)/", $service, $read);

        foreach ($keys[1] as $key) {
            self::assertContains(strtoupper($key), $read[1], $key);
        }
    }

    /** Цих ключів немає в мові ядра: на чистому OkayCMS заголовки були б порожні. */
    public function testGuideDoesNotDependOnKeysMissingFromCore(): void
    {
        self::assertStringNotContainsString('general_module_', $this->template());
    }

    public function testStatusComesFirst(): void
    {
        $template = $this->template();

        self::assertNotFalse(strpos($template, 'sviat_redis_status'));
        self::assertLessThan(strrpos($template, '<form'), strpos($template, 'sviat_redis_status'));
    }

    public function testEveryStateHasAnIconAndAnExplanation(): void
    {
        $template = $this->template();

        self::assertStringNotContainsString('sviat_redis_status__dot', $template);
        self::assertSame(3, substr_count($template, '<svg'));

        foreach (['ua', 'en', 'ru'] as $language) {
            $lang = [];
            require self::MODULE . "/Backend/lang/$language.php";

            self::assertNotEmpty($lang['sviat_redis_state_ok_hint'] ?? null, $language);
            self::assertLessThanOrEqual(16, mb_strlen($lang['sviat_redis_flush_current_db']), "$language: кнопка стоїть у рядку стану");
        }
    }

    public function testFlushHasItsOwnForm(): void
    {
        $template = $this->template();
        $flush = strpos($template, 'value="flush_helpers"');
        $main = strrpos($template, '<form');

        self::assertNotFalse($flush);
        self::assertLessThan($main, $flush, 'очищення стоїть поза формою налаштувань');
        self::assertSame(2, substr_count($template, 'name="session_id"'));
    }

    public function testSecondaryActionsAreOutlined(): void
    {
        self::assertStringNotContainsString('btn-secondary', $this->template());
        self::assertSame(2, substr_count($this->template(), 'btn_border_blue'));
    }

    public function testSuccessfulFlushIsConfirmed(): void
    {
        self::assertMatchesRegularExpression('/\{if \$flush_done\}.*sviat_redis_flush_done\|escape/s', $this->template());

        foreach (['ua', 'en', 'ru'] as $language) {
            $lang = [];
            require self::MODULE . "/Backend/lang/$language.php";
            self::assertNotEmpty($lang['sviat_redis_flush_done'] ?? null, $language);
        }
    }

    public function testActionsApplyToBothColumns(): void
    {
        $template = $this->template();

        self::assertGreaterThan(strrpos($template, 'name="ttl_authors_get_list"'), strpos($template, 'value="save"'));
        self::assertLessThan(strpos($template, 'value="test"'), strpos($template, 'value="save"'), 'Enter натискає першу кнопку');
    }

    public function testTtlFieldsHaveHumanLabels(): void
    {
        preg_match_all('/name="(ttl_\w+)"/', $this->template(), $fields);
        self::assertCount(12, $fields[1]);

        foreach (['ua', 'en', 'ru'] as $language) {
            $lang = [];
            require self::MODULE . "/Backend/lang/$language.php";

            foreach ($fields[1] as $field) {
                self::assertNotEmpty($lang['sviat_redis_label_' . $field] ?? null, "$language: $field");
            }
        }
    }

    /** На стоку немає токенів --ok-*: без запасного значення колір просто зникне. */
    public function testStylesWorkWithoutForkTokens(): void
    {
        $css = (string) file_get_contents(self::MODULE . '/Backend/design/css/redis_settings.css');

        self::assertNotSame('', $css);
        self::assertSame(0, preg_match('/var\(--[\w-]+\)/', $css), 'кожен var() має запасне значення');
        self::assertStringContainsString("new Css('redis_settings.css')", (string) file_get_contents(self::MODULE . '/Backend/design/css.php'));
    }
}
