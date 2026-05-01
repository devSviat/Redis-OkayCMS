<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class BrandCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    public function onBrandUpdate($output, $ids, $object): void
    {
        if (!$output) {
            return;
        }
        $this->redis->bump(CacheTags::BRANDS);
        $this->redis->bump(CacheTags::PRODUCTS_LIST);
    }

    public function onBrandAdd($output, $object): void
    {
        if ((int) $output > 0) {
            $this->redis->bump(CacheTags::BRANDS);
            $this->redis->bump(CacheTags::PRODUCTS_LIST);
        }
    }

    public function onBrandDelete($output, $ids): void
    {
        if (!$output) {
            return;
        }
        $this->redis->bump(CacheTags::BRANDS);
        $this->redis->bump(CacheTags::PRODUCTS_LIST);
    }
}
