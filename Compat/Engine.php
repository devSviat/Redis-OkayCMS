<?php

namespace Okay\Modules\Sviat\Redis\Compat;

/**
 * Питання до рушія — тільки за можливостями, ніколи за номером версії.
 *
 * Номер для цього непридатний: і наш форк, і сток звуть себе "4.5.2"
 * (Okay\Core\Config::$version), тож перевірка на версію мовчки розповідала б
 * неправду. Надійно розрізняє рушії лише наявність конкретного класу, методу
 * чи сусіднього модуля.
 *
 * Тут лише запитання про можливості — без стану й без вводу-виводу, тому
 * статика доречна. Усе, що читає стан запиту чи сесії, статикою бути не може:
 * така залежність невидима в сигнатурі й непіддатна підміні в тесті. Для
 * такого — порт і адаптери, а вибір реалізації робить Init/services.php
 * (див. Compat\AdminIdentity).
 */
final class Engine
{
    public static function hasClass(string $class): bool
    {
        return class_exists($class) || interface_exists($class);
    }

    public static function hasMethod(string $class, string $method): bool
    {
        return self::hasClass($class) && method_exists($class, $method);
    }

    /**
     * Чи лежить у дереві сусідній модуль. Саме «лежить», а не «встановлений і
     * ввімкнений»: цей стан живе в БД, а Init читає його не завжди й не всюди.
     * Для захисту від фатала при відсутньому модулі теки достатньо.
     */
    public static function hasModule(string $vendor, string $module): bool
    {
        return is_dir(__DIR__ . '/../../../' . $vendor . '/' . $module);
    }

    /**
     * Які з вимог не виконані. Запис вимоги:
     *   'Okay\Core\Design'            — клас або інтерфейс
     *   'Okay\Core\Design::minifyOutput' — метод класу
     *   'OkayCMS/NovaposhtaCost'      — сусідній модуль
     *
     * @param string[] $requirements
     * @return string[] невиконані вимоги, тим самим записом
     */
    public static function missing(array $requirements): array
    {
        $unmet = [];

        foreach ($requirements as $requirement) {
            if (strpos($requirement, '::') !== false) {
                list($class, $method) = explode('::', $requirement, 2);
                $met = self::hasMethod($class, $method);
            } elseif (strpos($requirement, '/') !== false) {
                list($vendor, $module) = explode('/', $requirement, 2);
                $met = self::hasModule($vendor, $module);
            } else {
                $met = self::hasClass($requirement);
            }

            if (!$met) {
                $unmet[] = $requirement;
            }
        }

        return $unmet;
    }
}
