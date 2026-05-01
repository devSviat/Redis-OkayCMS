<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class MoneyHelper extends \Okay\Helpers\MoneyHelper
{
    private EntityFactory $entityFactory;
    private Settings $settings;
    private RedisCacheService $redis;

    public function __construct(EntityFactory $entityFactory, Settings $settings, RedisCacheService $redis)
    {
        parent::__construct($entityFactory, $settings);
        $this->entityFactory = $entityFactory;
        $this->settings = $settings;
        $this->redis = $redis;
    }

    private function getCurrenciesList(): array
    {
        if (!$this->redis->isEnabled()) {
            return $this->loadCurrenciesFromDb();
        }
        $key = $this->redis->makeVersionedKey('money_currencies_list', [CacheTags::MONEY], []);
        $cached = $this->redis->get($key);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }
        $currencies = $this->loadCurrenciesFromDb();
        if (!empty($currencies)) {
            $this->redis->set($key, $currencies, $this->redis->getHelperTtl('money_currencies_list') ?? 3600);
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
