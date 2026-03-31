<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Money as MoneyCore;
use Okay\Core\Request;
use Okay\Core\Settings;
use Okay\Helpers\FilterHelper;
use Okay\Helpers\MetaRobotsHelper;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class CatalogHelper extends \Okay\Helpers\CatalogHelper
{
    private RedisCacheService $redisCache;

    public function __construct(
        EntityFactory    $entityFactory,
        MoneyCore        $money,
        Settings         $settings,
        Request          $request,
        FilterHelper     $filterHelper,
        MetaRobotsHelper $metaRobotsHelper,
        Design           $design,
        RedisCacheService $redisCache
    ) {
        parent::__construct(
            $entityFactory,
            $money,
            $settings,
            $request,
            $filterHelper,
            $metaRobotsHelper,
            $design
        );
        $this->redisCache = $redisCache;
    }

    public function getCatalogFeaturesFilter(): array
    {
        if (!$this->redisCache->canCache('catalog_features_filter')) {
            return parent::getCatalogFeaturesFilter();
        }

        $ttl = $this->redisCache->getHelperTtl('catalog_features_filter');
        $key = $this->redisCache->makeKey('catalog_features_filter');

        $cached = $this->redisCache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $result = parent::getCatalogFeaturesFilter();
        $this->redisCache->set($key, $result, $ttl);

        return $result;
    }

    public function getCatalogFeatures(?array $filter = null): array
    {
        if (!$this->redisCache->canCache('catalog_features')) {
            return parent::getCatalogFeatures($filter);
        }

        $ttl = $this->redisCache->getHelperTtl('catalog_features');
        $key = $this->redisCache->makeKey('catalog_features', [$filter]);

        $cached = $this->redisCache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $result = parent::getCatalogFeatures($filter);
        $this->redisCache->set($key, $result, $ttl);

        return $result;
    }
}

