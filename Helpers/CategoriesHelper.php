<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Settings;
use Okay\Helpers\CatalogHelper;
use Okay\Helpers\FilterHelper;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class CategoriesHelper extends \Okay\Helpers\CategoriesHelper
{
    private RedisCacheService $redisCache;

    public function __construct(
        CatalogHelper      $catalogHelper,
        EntityFactory      $entityFactory,
        Settings           $settings,
        Design             $design,
        FilterHelper       $filterHelper,
        RedisCacheService  $redisCache
    ) {
        parent::__construct($catalogHelper, $entityFactory, $settings, $design, $filterHelper);
        $this->redisCache = $redisCache;
    }

    public function getCatalogFeatures(object $category): array
    {
        if (!$this->redisCache->isEnabled()) {
            return parent::getCatalogFeatures($category);
        }
        $categoryId = $category->id ?? null;
        $key = $this->redisCache->makeVersionedKey(
            'categories_catalog_features',
            [CacheTags::CATEGORIES, CacheTags::PRODUCTS_ALL],
            [$categoryId]
        );
        $cached = $this->redisCache->get($key);
        if ($cached !== null) {
            return ExtenderFacade::execute(
                \Okay\Helpers\CategoriesHelper::class . '::getCatalogFeatures',
                $cached,
                func_get_args()
            );
        }
        $result = parent::getCatalogFeatures($category);
        $this->redisCache->set($key, $result, $this->redisCache->getHelperTtl('categories_catalog_features'));
        return $result;
    }
}
