<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Money as MoneyCore;
use Okay\Core\Request;
use Okay\Core\Settings;
use Okay\Helpers\FilterHelper;
use Okay\Helpers\MetaRobotsHelper;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class CatalogHelper extends \Okay\Helpers\CatalogHelper
{
    private RedisCacheService $redisCache;

    public function __construct(
        EntityFactory     $entityFactory,
        MoneyCore         $money,
        Settings          $settings,
        Request           $request,
        FilterHelper      $filterHelper,
        MetaRobotsHelper  $metaRobotsHelper,
        Design            $design,
        RedisCacheService $redisCache
    ) {
        parent::__construct($entityFactory, $money, $settings, $request, $filterHelper, $metaRobotsHelper, $design);
        $this->redisCache = $redisCache;
    }

    public function getCatalogFeaturesFilter(): array
    {
        if (!$this->redisCache->isEnabled()) {
            return parent::getCatalogFeaturesFilter();
        }
        $key = $this->redisCache->makeVersionedKey('catalog_features_filter', [CacheTags::PRODUCTS_ALL], []);
        $cached = $this->redisCache->get($key);
        if ($cached !== null) {
            return ExtenderFacade::execute(
                \Okay\Helpers\CatalogHelper::class . '::getCatalogFeaturesFilter',
                $cached,
                func_get_args()
            );
        }
        $result = parent::getCatalogFeaturesFilter();
        $this->redisCache->set($key, $result, $this->redisCache->getHelperTtl('catalog_features_filter'));
        return $result;
    }

    public function getCatalogFeatures(?array $filter = null): array
    {
        if (!$this->redisCache->isEnabled()) {
            return parent::getCatalogFeatures($filter);
        }
        $key = $this->redisCache->makeVersionedKey('catalog_features', [CacheTags::PRODUCTS_ALL], [$filter]);
        $cached = $this->redisCache->get($key);
        if ($cached !== null) {
            return ExtenderFacade::execute(
                \Okay\Helpers\CatalogHelper::class . '::getCatalogFeatures',
                $cached,
                func_get_args()
            );
        }
        $result = parent::getCatalogFeatures($filter);
        $this->redisCache->set($key, $result, $this->redisCache->getHelperTtl('catalog_features'));
        return $result;
    }
}
