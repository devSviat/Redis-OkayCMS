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
    private FilterHelper $filterHelper;

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
        $this->filterHelper = $filterHelper;
    }

    public function getCatalogFeaturesFilter(): array
    {
        if (!$this->redisCache->isEnabled() || $this->isSearchRequest()) {
            return parent::getCatalogFeaturesFilter();
        }
        $args = func_get_args();

        return $this->redisCache->remember(
            'catalog_features_filter',
            [CacheTags::PRODUCTS_ALL],
            [],
            fn () => parent::getCatalogFeaturesFilter(),
            $this->redisCache->getHelperTtl('catalog_features_filter'),
            fn ($cached) => ExtenderFacade::execute(
                \Okay\Helpers\CatalogHelper::class . '::getCatalogFeaturesFilter',
                $cached,
                $args
            )
        );
    }

    public function getCatalogFeatures(?array $filter = null): array
    {
        // При $filter === null фільтр збирається всередині parent і теж залежить від keyword.
        if (!$this->redisCache->isEnabled() || ($filter === null && $this->isSearchRequest())) {
            return parent::getCatalogFeatures($filter);
        }
        $args = func_get_args();

        return $this->redisCache->remember(
            'catalog_features',
            [CacheTags::PRODUCTS_ALL],
            [$filter],
            fn () => parent::getCatalogFeatures($filter),
            $this->redisCache->getHelperTtl('catalog_features'),
            fn ($cached) => ExtenderFacade::execute(
                \Okay\Helpers\CatalogHelper::class . '::getCatalogFeatures',
                $cached,
                $args
            )
        );
    }

    /**
     * Ці значення залежать від keyword запиту, а ключ кеша — ні: фільтр, покладений
     * туди пошуком, віддавався б усьому каталогу до кінця TTL. Окремий ключ на
     * кожен запит сенсу не має — пошукові фрази майже не повторюються.
     */
    private function isSearchRequest(): bool
    {
        return $this->filterHelper->getKeyword() !== null;
    }
}
