<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class VariantsCacheExtender implements ExtensionInterface
{
    private EntityFactory $entityFactory;
    private RedisCacheService $redis;

    public function __construct(EntityFactory $entityFactory, RedisCacheService $redis)
    {
        $this->entityFactory = $entityFactory;
        $this->redis = $redis;
    }

    /** Triggered after `VariantsEntity::update`. */
    public function onVariantsUpdate($output, $ids, $object): void
    {
        if (!$output) {
            return;
        }
        $this->bumpByVariantIds((array) $ids);
    }

    /** Triggered after `VariantsEntity::add`. */
    public function onVariantsAdd($output, $object): void
    {
        $id = (int) $output;
        if ($id <= 0) {
            return;
        }
        $this->bumpByVariantIds([$id]);
    }

    /** Triggered after `VariantsEntity::delete`. */
    public function onVariantsDelete($output, $ids): void
    {
        if (!$output) {
            return;
        }

        // After delete product_id may be unavailable, so use global invalidation.
        $this->redis->bumpGlobalVariantsVersion();
    }

    private function bumpByVariantIds(array $variantIds): void
    {
        $variantIds = array_values(array_filter(array_map('intval', $variantIds)));
        if (empty($variantIds)) {
            return;
        }

        /** @var VariantsEntity $variantsEntity */
        $variantsEntity = $this->entityFactory->get(VariantsEntity::class);
        $variants = $variantsEntity->find(['id' => $variantIds]);
        if (empty($variants)) {
            return;
        }

        $productIds = [];
        foreach ($variants as $variant) {
            if (!empty($variant->product_id)) {
                $productIds[(int) $variant->product_id] = (int) $variant->product_id;
            }
        }

        foreach ($productIds as $productId) {
            $this->redis->bumpProductVariantsVersion((int) $productId);
        }
    }
}

