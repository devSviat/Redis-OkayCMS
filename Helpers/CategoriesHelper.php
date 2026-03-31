<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Helpers\CatalogHelper;
use Okay\Helpers\FilterHelper;
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
        if (!$this->redisCache->canCache('categories_catalog_features')) {
            return parent::getCatalogFeatures($category);
        }

        $categoryId = $category->id ?? null;
        $ttl = $this->redisCache->getHelperTtl('categories_catalog_features');
        $key = $this->redisCache->makeKey('categories_catalog_features', [$categoryId]);

        $cached = $this->redisCache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $result = parent::getCatalogFeatures($category);
        $this->redisCache->set($key, $result, $ttl);

        return $result;
    }
}

