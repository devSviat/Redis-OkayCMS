<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class VariantsCacheInvalidator implements ExtensionInterface
{
    private EntityFactory $entityFactory;
    private RedisCacheService $redis;

    public function __construct(EntityFactory $entityFactory, RedisCacheService $redis)
    {
        $this->entityFactory = $entityFactory;
        $this->redis = $redis;
    }

    public function onVariantsUpdate($output, $ids, $object): void
    {
        if (!$output) {
            return;
        }
        $this->bumpByVariantIds((array) $ids);
    }

    public function onVariantsAdd($output, $object): void
    {
        $id = (int) $output;
        if ($id <= 0) {
            return;
        }
        $this->bumpByVariantIds([$id]);
    }

    public function onVariantsDelete($output, $ids): void
    {
        if (!$output) {
            return;
        }
        $this->redis->bump(CacheTags::PRODUCTS_ALL);
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
        foreach ($variants as $v) {
            if (!empty($v->product_id)) {
                $productIds[(int) $v->product_id] = (int) $v->product_id;
            }
        }
        foreach ($productIds as $pid) {
            $this->redis->bump(CacheTags::product($pid));
        }
    }
}
