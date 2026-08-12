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
 * Обхідні шляхи додаються сюди й тільки сюди — щоб різниця рушіїв не
 * розповзалася по коду модуля.
 */
final class Engine
{
    /**
     * Логін менеджера, залогіненого в адмінці, або null.
     *
     * Єдина точка, де рушії справді розходяться. Там, де сесії вітрини й
     * адмінки розділені на різні куки, $_SESSION вітрини бекендового логіна
     * не бачить взагалі, і читати його треба окремо. Там, де сесія одна,
     * логін лежить у $_SESSION['admin'] — саме його очікує ManagersEntity::get().
     */
    public static function adminLogin(): ?string
    {
        $sessionNames = 'Okay\Core\Security\SessionNames';

        if (self::hasMethod($sessionNames, 'adminLogin')) {
            return $sessionNames::adminLogin();
        }

        return empty($_SESSION['admin']) ? null : (string) $_SESSION['admin'];
    }

    /**
     * Мажорна версія Smarty: 5 у форку, 3 у стоці, 0 якщо шаблонізатор ще не
     * завантажений. Smarty 5 живе в неймспейсі, Smarty 3 — у корені; це
     * найдешевша однозначна ознака рушія.
     */
    public static function smartyMajor(): int
    {
        if (class_exists('Smarty\Smarty')) {
            return 5;
        }

        return class_exists('Smarty') ? 3 : 0;
    }

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
