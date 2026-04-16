<?php

$lang['sviat_redis_settings'] = 'Redis helper cache';
$lang['sviat_redis_connection_success'] = 'Redis connection successful';
$lang['sviat_redis_connection_failed'] = 'Failed to connect to Redis';
$lang['sviat_redis_connection_box'] = 'Redis connection';
$lang['sviat_redis_enable_cache'] = 'Enable Redis cache';
$lang['sviat_redis_host'] = 'Host';
$lang['sviat_redis_port'] = 'Port';
$lang['sviat_redis_db'] = 'DB';
$lang['sviat_redis_username'] = 'Username';
$lang['sviat_redis_username_placeholder'] = 'ACL (Redis 6+), empty = password only';
$lang['sviat_redis_password'] = 'Password';
$lang['sviat_redis_password_placeholder'] = 'Empty = no authentication';
$lang['sviat_redis_key_prefix'] = 'Key prefix';
$lang['sviat_redis_default_ttl'] = 'Default TTL (sec)';
$lang['sviat_redis_cache_hmac_secret'] = 'Cache signing secret (HMAC)';
$lang['sviat_redis_cache_hmac_secret_placeholder'] = 'Empty = legacy mode without signing';
$lang['sviat_redis_cache_hmac_secret_hint'] = 'If you provide a long random string, values from set/get/mGet are signed before being written to Redis; external unsigned entries are ignored (unserialize runs only after verification). Existing keys should be refreshed or the cache should be cleared before their TTL expires.';
$lang['sviat_redis_test_connection'] = 'Test connection';
$lang['sviat_redis_cache_status'] = 'Cache status';
$lang['sviat_redis_db_keys_count'] = 'Number of keys in DB';
$lang['sviat_redis_used_memory'] = 'Used memory';
$lang['sviat_redis_flush_current_db'] = 'Clear all Redis cache (current DB)';
$lang['sviat_redis_unavailable'] = 'Redis is unavailable';
$lang['sviat_redis_helpers_ttl'] = 'TTL for helpers';
