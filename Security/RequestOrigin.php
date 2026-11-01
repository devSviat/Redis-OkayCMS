<?php

namespace Okay\Modules\Sviat\Redis\Security;

use Okay\Core\Request;

/**
 * Чи надійшов мутуючий запит зі сторінки цього ж магазину.
 *
 * Маршрути модуля оголошені з to_front, тож запит іде повз авторизацію
 * бекенду. AdminIdentity відповідає лише на питання «хто», а це — відповідь
 * на «звідки»: без неї сторонній сайт, відкритий у браузері залогіненого
 * менеджера, міг би смикнути дію від його імені.
 *
 * Власна реалізація, а не Okay\Core\Security\RequestOrigin: у стоковому
 * OkayCMS теки Okay/Core/Security немає взагалі. Гілка через class_exists
 * дала б модулю різну поведінку на різних рушіях — саме той різновид
 * розбіжності, який потім ловиться найдовше.
 */
class RequestOrigin
{
    /** Порти, які в origin не пишуться. */
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public static function isFromThisSite(): bool
    {
        $expected = self::normalize(Request::getDomainWithProtocol());
        if ($expected === null) {
            return false;
        }

        $origin  = $_SERVER['HTTP_ORIGIN'] ?? null;
        $referer = $_SERVER['HTTP_REFERER'] ?? null;

        // Origin виграє: браузер ставить його сам, а Referer ріжуть проксі
        // й розширення приватності. Другий кандидат — лише коли першого немає.
        foreach ([$origin, $referer] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            // Пісочниця в iframe і частина редиректів шлють рядок "null".
            // Це не наш origin, і запасний варіант тут уже не рятує.
            if (strtolower($candidate) === 'null') {
                return false;
            }

            return self::normalize($candidate) === $expected;
        }

        // Жодного доказу походження — жодного проходу.
        return false;
    }

    /**
     * @return string|null scheme://host[:port], або null, якщо розібрати не вдалось
     */
    private static function normalize(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $parsed = @parse_url($url);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            return null;
        }

        $scheme = strtolower($parsed['scheme']);
        if (!isset(self::DEFAULT_PORTS[$scheme])) {
            return null;
        }

        // Браузер серіалізує Origin як scheme://host[:port] і облікових даних
        // туди не кладе. Раз їх не буває в чесному заголовку — не приймаємо.
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return null;
        }

        $normalized = $scheme . '://' . strtolower($parsed['host']);

        if (isset($parsed['port']) && (int) $parsed['port'] !== self::DEFAULT_PORTS[$scheme]) {
            $normalized .= ':' . (int) $parsed['port'];
        }

        return $normalized;
    }
}
