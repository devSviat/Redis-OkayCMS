<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\Entity\RelatedProductsInterface;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
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
        if (!$this->redis->isEnabled()) {
            return parent::getRelatedProductsList($relatedObjectsEntity, $filter);
        }
        $key = $this->redis->makeVersionedKey(
            'related_products_list',
            [CacheTags::PRODUCTS_ALL, CacheTags::PRODUCTS_LIST],
            [get_class($relatedObjectsEntity), $filter]
        );
        $cached = $this->redis->get($key);
        if (is_array($cached)) {
            return ExtenderFacade::execute(
                \Okay\Helpers\RelatedProductsHelper::class . '::getRelatedProductsList',
                $cached,
                func_get_args()
            );
        }
        $result = parent::getRelatedProductsList($relatedObjectsEntity, $filter);
        $this->redis->set($key, $result, $this->redis->getHelperTtl('related_products_list') ?? 600);
        return $result;
    }
}
