<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class CategoryCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    public function onCategoryUpdate($output, $ids, $object): void
    {
        if (!$output) {
            return;
        }
        $this->redis->bump(CacheTags::CATEGORIES);
        $this->redis->bump(CacheTags::PRODUCTS_LIST);
    }

    public function onCategoryAdd($output, $object): void
    {
        if ((int) $output > 0) {
            $this->redis->bump(CacheTags::CATEGORIES);
            $this->redis->bump(CacheTags::PRODUCTS_LIST);
        }
    }

    public function onCategoryDelete($output, $ids): void
    {
        if (!$output) {
            return;
        }
        $this->redis->bump(CacheTags::CATEGORIES);
        $this->redis->bump(CacheTags::PRODUCTS_LIST);
    }
}
