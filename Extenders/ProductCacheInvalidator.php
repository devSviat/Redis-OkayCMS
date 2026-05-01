<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class ProductCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    public function onProductUpdate($output, $ids, $object): void
    {
        if (!$output) {
            return;
        }
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $this->redis->bump(CacheTags::product($id));
            }
        }
        $this->redis->bump(CacheTags::PRODUCTS_LIST);
    }

    public function onProductAdd($output, $object): void
    {
        if ((int) $output > 0) {
            $this->redis->bump(CacheTags::PRODUCTS_LIST);
        }
    }

    public function onProductDelete($output, $ids): void
    {
        if (!$output) {
            return;
        }
        $this->redis->bump(CacheTags::PRODUCTS_ALL);
        $this->redis->bump(CacheTags::PRODUCTS_LIST);
    }
}
