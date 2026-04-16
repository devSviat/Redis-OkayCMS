<?php

$lang['sviat_redis_settings'] = 'Redis кеш хелперів';
$lang['sviat_redis_connection_success'] = 'Підключення до Redis успішне';
$lang['sviat_redis_connection_failed'] = 'Не вдалося підключитись до Redis';
$lang['sviat_redis_connection_box'] = 'Підключення до Redis';
$lang['sviat_redis_enable_cache'] = 'Увімкнути Redis кеш';
$lang['sviat_redis_host'] = 'Host';
$lang['sviat_redis_port'] = 'Port';
$lang['sviat_redis_db'] = 'DB';
$lang['sviat_redis_username'] = 'Username';
$lang['sviat_redis_username_placeholder'] = 'ACL (Redis 6+), порожньо = лише пароль';
$lang['sviat_redis_password'] = 'Password';
$lang['sviat_redis_password_placeholder'] = 'Порожньо = без авторизації';
$lang['sviat_redis_key_prefix'] = 'Префікс ключів';
$lang['sviat_redis_default_ttl'] = 'TTL за замовчуванням (сек)';
$lang['sviat_redis_cache_hmac_secret'] = 'Секрет підпису кешу (HMAC)';
$lang['sviat_redis_cache_hmac_secret_placeholder'] = 'Порожньо = як раніше без підпису';
$lang['sviat_redis_cache_hmac_secret_hint'] = 'Якщо вказати довгий випадковий рядок, значення з set/get/mGet підписуються перед записом у Redis; сторонні записи без підпису ігноруються (unserialize лише після перевірки). Існуючі ключі до завершення TTL слід оновити або очистити кеш.';
$lang['sviat_redis_test_connection'] = 'Протестувати підключення';
$lang['sviat_redis_cache_status'] = 'Стан кешу';
$lang['sviat_redis_db_keys_count'] = 'Кількість ключів у БД';
$lang['sviat_redis_used_memory'] = 'Використана пам\'ять';
$lang['sviat_redis_flush_current_db'] = 'Очистити весь кеш Redis (поточна DB)';
$lang['sviat_redis_unavailable'] = 'Redis недоступний';
$lang['sviat_redis_helpers_ttl'] = 'TTL для хелперів';

