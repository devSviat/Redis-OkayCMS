<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class CurrencyCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    public function onCurrencyUpdate($output, $ids, $object): void
    {
        if (!$output) { return; }
        $this->redis->bumpOnce(CacheTags::MONEY);
        $this->redis->bumpOnce(CacheTags::PRODUCTS_ALL);
    }

    public function onCurrencyAdd($output, $object): void
    {
        if ((int) $output <= 0) { return; }
        $this->redis->bumpOnce(CacheTags::MONEY);
        $this->redis->bumpOnce(CacheTags::PRODUCTS_ALL);
    }

    public function onCurrencyDelete($output, $ids): void
    {
        if (!$output) { return; }
        $this->redis->bumpOnce(CacheTags::MONEY);
        $this->redis->bumpOnce(CacheTags::PRODUCTS_ALL);
    }
}
