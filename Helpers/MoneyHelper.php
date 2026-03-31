<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class MoneyHelper extends \Okay\Helpers\MoneyHelper
{
    private EntityFactory $entityFactory;
    private Settings $settings;
    private RedisCacheService $redis;

    public function __construct(
        EntityFactory $entityFactory,
        Settings $settings,
        RedisCacheService $redis
    ) {
        parent::__construct($entityFactory, $settings);
        $this->entityFactory = $entityFactory;
        $this->settings = $settings;
        $this->redis = $redis;
    }

    /** Load currencies from cache, fallback to DB. */
    private function getCurrenciesList(): array
    {
        if (!$this->redis->canCache('money_currencies_list')) {
            return $this->loadCurrenciesFromDb();
        }

        $cacheKey = $this->redis->makeKey('money_currencies_list', []);
        $cached = $this->redis->get($cacheKey);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $currencies = $this->loadCurrenciesFromDb();
        if (!empty($currencies)) {
            $ttl = $this->redis->getHelperTtl('money_currencies_list');
            if ($ttl === null) {
                $ttl = 3600; // Default TTL: 1 hour.
            }
            $this->redis->set($cacheKey, $currencies, $ttl);
        }

        return $currencies;
    }

    private function loadCurrenciesFromDb(): array
    {
        /** @var CurrenciesEntity $currenciesEntity */
        $currenciesEntity = $this->entityFactory->get(CurrenciesEntity::class);
        return $currenciesEntity->mappedBy('id')->find();
    }

    public function convertVariantPriceToMainCurrency($variant)
    {
        if (empty($variant)) {
            return ExtenderFacade::execute(__METHOD__, $variant, func_get_args());
        }

        if ($this->settings->get('hide_equal_compare_price') && $variant->compare_price <= $variant->price) {
            $variant->compare_price = null;
        }

        $currencies = $this->getCurrenciesList();
        if (!isset($currencies[$variant->currency_id])) {
            return ExtenderFacade::execute(__METHOD__, $variant, func_get_args());
        }

        $variantCurrency = $currencies[$variant->currency_id];
        if (!empty($variant->currency_id) && $variantCurrency->rate_from != $variantCurrency->rate_to) {
            $variant->price = round($variant->price * $variantCurrency->rate_to / $variantCurrency->rate_from, 2);
            if (!empty($variant->compare_price)) {
                $variant->compare_price = round($variant->compare_price * $variantCurrency->rate_to / $variantCurrency->rate_from, 2);
            }
        }

        return ExtenderFacade::execute(__METHOD__, $variant, func_get_args());
    }
}
