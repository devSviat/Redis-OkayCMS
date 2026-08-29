<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Перерахунок після відповіді мусить спершу віддати файловий замок сесії.
 * PHP закриває сесію лише після shutdown-функцій, тож без явного закриття
 * наступний запит того самого відвідувача стоїть на session_start() рівно
 * стільки, скільки ми зекономили йому на попередній сторінці.
 */
class DeferredRefreshSessionTest extends TestCase
{
    /**
     * Анотація поряд з атрибутом — шим для PHPUnit 9.5 у лабі: там атрибути ще
     * не читаються, і тест поїхав би в спільному процесі, де сесію вже не
     * налаштувати.
     *
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function testDeferredTaskRunsWithTheSessionClosed(): void
    {
        if (!class_exists('\\Redis')) { $this->markTestSkipped('phpredis not installed'); }

        session_save_path(sys_get_temp_dir());
        session_start();
        $_SESSION['x'] = 1;
        self::assertSame(PHP_SESSION_ACTIVE, session_status(), 'сесія відкрита до відкладеної роботи');

        $service = new class ($this->createStub(Settings::class)) extends RedisCacheService {
            public ?int $statusInsideTask = null;

            public function run(): void
            {
                $this->runDeferred(function (): void {
                    $this->statusInsideTask = session_status();
                });
            }
        };

        $service->run();

        self::assertNotSame(
            PHP_SESSION_ACTIVE,
            $service->statusInsideTask,
            'замок сесії має бути відданий до перерахунку'
        );
    }
}
