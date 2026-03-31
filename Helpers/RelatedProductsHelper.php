<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\Entity\RelatedProductsInterface;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class RelatedProductsHelper extends \Okay\Helpers\RelatedProductsHelper
{
    private RedisCacheService $redis;

    public function __construct(
        \Okay\Helpers\ProductsHelper $productsHelper,
        RedisCacheService $redis
    ) {
        parent::__construct($productsHelper);
        $this->redis = $redis;
    }

    public function getRelatedProductsList(RelatedProductsInterface $relatedObjectsEntity, array $filter)
    {
        if (!$this->redis->canCache('related_products_list')) {
            return parent::getRelatedProductsList($relatedObjectsEntity, $filter);
        }

        // Include entity class to avoid key collisions.
        $key = $this->redis->makeKey('related_products_list', [get_class($relatedObjectsEntity), $filter]);
        $cached = $this->redis->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = parent::getRelatedProductsList($relatedObjectsEntity, $filter);
        $this->redis->set($key, $result, $this->redis->getHelperTtl('related_products_list') ?? 600);
        return $result;
    }
}

