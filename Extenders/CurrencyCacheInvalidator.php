<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

/**
 * Інвалідує кеш валют і грошових операцій при їх зміні.
 */
class CurrencyCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    /** Triggered after `CurrenciesEntity::update`. */
    public function onCurrencyUpdate($output, $ids, $object): void
    {
        if (!$output) {
            return;
        }

        $this->redis->invalidateMoneyCaches();
    }

    /** Triggered after `CurrenciesEntity::add`. */
    public function onCurrencyAdd($output, $object): void
    {
        if ($output) {
            // Новая валюта может повлиять на цены
            $this->redis->invalidateMoneyCaches();
        }
    }

    /** Triggered after `CurrenciesEntity::delete`. */
    public function onCurrencyDelete($output, $ids): void
    {
        if (!$output) {
            return;
        }

        $this->redis->invalidateMoneyCaches();
    }
}
