<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class ImagesCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    public function onImageAdd($output, $object): void
    {
        if ((int) $output <= 0) { return; }
        $this->bumpForObject($object);
    }

    public function onImageUpdate($output, $ids, $object): void
    {
        if (!$output) { return; }
        $this->bumpForObject($object);
    }

    public function onImageDelete($output, $ids): void
    {
        if (!$output) { return; }
        // product_id is gone after delete; invalidate globally.
        $this->redis->bump(CacheTags::PRODUCTS_ALL);
    }

    private function bumpForObject($object): void
    {
        $productId = $this->extractProductId($object);
        if ($productId > 0) {
            $this->redis->bump(CacheTags::product($productId));
            return;
        }
        $this->redis->bump(CacheTags::PRODUCTS_ALL);
    }

    private function extractProductId($object): int
    {
        if (is_object($object) && isset($object->product_id)) {
            return (int) $object->product_id;
        }
        if (is_array($object) && isset($object['product_id'])) {
            return (int) $object['product_id'];
        }
        return 0;
    }
}
