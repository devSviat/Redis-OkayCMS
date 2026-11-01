<?php

declare(strict_types=1);

namespace Okay\Modules\Sviat\Redis\Security;

/**
 * Чи можна зберегти значення форми в налаштування.
 *
 * Стокове ядро робить unserialize() без обмеження класів на кожне значення
 * налаштувань при кожному запиті, тож рядок у форматі serialize стає об'єктом.
 * На форку ядро обмежує класи. Перевірка тут, щоб поведінка не залежала від рушія.
 */
final class PlainSettingValue
{
    public static function isPlain($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        set_error_handler(static fn (): bool => true);
        $decoded = unserialize($value, ['allowed_classes' => false]);
        restore_error_handler();

        return $decoded === false && $value !== serialize(false);
    }
}
