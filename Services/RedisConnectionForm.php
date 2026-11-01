<?php

namespace Okay\Modules\Sviat\Redis\Services;

use Okay\Modules\Sviat\Redis\Security\PlainSettingValue;

/**
 * Перевіряє поля підключення з форми адмінки.
 *
 * Клас без залежностей: правила однакові на обох рушіях і перевіряються тестом
 * без контролера. Поле з $locked не змінюється: його задано поза адмінкою.
 */
final class RedisConnectionForm
{
    private const int MAX_PREFIX_LENGTH = 64;

    /**
     * @param array<string, mixed>  $post   host, port, db, username, password, clear_password, prefix
     * @param array<string, string> $stored збережені значення тих самих полів
     * @param array<string, string> $locked поле => джерело
     * @return array{values: array<string, mixed>, errors: list<string>, passwordDropped: bool}
     */
    public static function apply(array $post, array $stored, array $locked = []): array
    {
        $values = [];
        $errors = [];

        $host = trim((string) ($post['host'] ?? ''));
        if (!isset($locked['host'])) {
            if (self::isValidHost($host)) {
                $values['host'] = $host;
            } else {
                $errors[] = 'host';
            }
        }

        $port = (int) ($post['port'] ?? 0);
        if (!isset($locked['port'])) {
            if ($port >= 1 && $port <= 65535) {
                $values['port'] = $port;
            } else {
                $errors[] = 'port';
            }
        }

        if (!isset($locked['db'])) {
            $values['db'] = max(0, (int) ($post['db'] ?? 0));
        }

        $username = trim((string) ($post['username'] ?? ''));
        if (!isset($locked['username'])) {
            if (PlainSettingValue::isPlain($username)) {
                $values['username'] = $username;
            } else {
                $errors[] = 'username';
            }
        }

        $prefix = (string) ($post['prefix'] ?? '');
        if (!isset($locked['prefix'])) {
            if (self::isValidPrefix($prefix)) {
                $values['prefix'] = $prefix;
            } else {
                $errors[] = 'prefix';
            }
        }

        $passwordDropped = false;
        if (!isset($locked['password'])) {
            $password = (string) ($post['password'] ?? '');
            $hasStored = (string) ($stored['password'] ?? '') !== '';

            if ($password !== '') {
                if (PlainSettingValue::isPlain($password)) {
                    $values['password'] = $password;
                } else {
                    $errors[] = 'password';
                }
            } elseif ((int) ($post['clear_password'] ?? 0) === 1) {
                $values['password'] = '';
            } elseif ($hasStored && self::targetChanged($values, $stored)) {
                // Збережений пароль належить старому серверу: на новий він пішов би відкритим AUTH.
                $values['password'] = '';
                $passwordDropped = true;
            }
        }

        return ['values' => $values, 'errors' => $errors, 'passwordDropped' => $passwordDropped];
    }

    /** Ім'я, IPv4, IPv6 у дужках, tls://ім'я або абсолютний шлях unix-сокета. */
    public static function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 255) {
            return false;
        }

        return preg_match('#^(?:(?:tls://)?[A-Za-z0-9][A-Za-z0-9.\-]*|\[[0-9A-Fa-f:]+\]|/[A-Za-z0-9._/\-]+)$#', $host) === 1;
    }

    public static function isValidPrefix(string $prefix): bool
    {
        return $prefix !== ''
            && strlen($prefix) <= self::MAX_PREFIX_LENGTH
            && preg_match('/\s/', $prefix) !== 1
            && PlainSettingValue::isPlain($prefix);
    }

    private static function targetChanged(array $values, array $stored): bool
    {
        foreach (['host', 'port', 'username'] as $field) {
            if (array_key_exists($field, $values) && (string) $values[$field] !== (string) ($stored[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
