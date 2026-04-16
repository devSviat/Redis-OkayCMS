<?php

$lang['sviat_redis_settings'] = 'Redis кеш хелперов';
$lang['sviat_redis_connection_success'] = 'Подключение к Redis успешно';
$lang['sviat_redis_connection_failed'] = 'Не удалось подключиться к Redis';
$lang['sviat_redis_connection_box'] = 'Подключение к Redis';
$lang['sviat_redis_enable_cache'] = 'Включить Redis кеш';
$lang['sviat_redis_host'] = 'Host';
$lang['sviat_redis_port'] = 'Port';
$lang['sviat_redis_db'] = 'DB';
$lang['sviat_redis_username'] = 'Username';
$lang['sviat_redis_username_placeholder'] = 'ACL (Redis 6+), пусто = только пароль';
$lang['sviat_redis_password'] = 'Password';
$lang['sviat_redis_password_placeholder'] = 'Пусто = без авторизации';
$lang['sviat_redis_key_prefix'] = 'Префикс ключей';
$lang['sviat_redis_default_ttl'] = 'TTL по умолчанию (сек)';
$lang['sviat_redis_cache_hmac_secret'] = 'Секрет подписи кеша (HMAC)';
$lang['sviat_redis_cache_hmac_secret_placeholder'] = 'Пусто = как раньше без подписи';
$lang['sviat_redis_cache_hmac_secret_hint'] = 'Если указать длинную случайную строку, значения из set/get/mGet подписываются перед записью в Redis; сторонние записи без подписи игнорируются (unserialize выполняется только после проверки). Существующие ключи следует обновить или очистить кеш до истечения TTL.';
$lang['sviat_redis_test_connection'] = 'Проверить подключение';
$lang['sviat_redis_cache_status'] = 'Состояние кеша';
$lang['sviat_redis_db_keys_count'] = 'Количество ключей в БД';
$lang['sviat_redis_used_memory'] = 'Используемая память';
$lang['sviat_redis_flush_current_db'] = 'Очистить весь кеш Redis (текущая DB)';
$lang['sviat_redis_unavailable'] = 'Redis недоступен';
$lang['sviat_redis_helpers_ttl'] = 'TTL для хелперов';
