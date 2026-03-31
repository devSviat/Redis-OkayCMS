<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Helpers\CatalogHelper;
use Okay\Helpers\FilterHelper;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class BrandsHelper extends \Okay\Helpers\BrandsHelper
{
    private RedisCacheService $redis;

    public function __construct(
        EntityFactory      $entityFactory,
        CatalogHelper      $catalogHelper,
        Settings           $settings,
        FilterHelper       $filterHelper,
        Design             $design,
        RedisCacheService  $redis
    ) {
        parent::__construct($entityFactory, $catalogHelper, $settings, $filterHelper, $design);
        $this->redis = $redis;
    }

    public function getList($filter = [], $sortName = null, $excludedFields = null)
    {
        if (!$this->redis->canCache('brands_get_list')) {
            return parent::getList($filter, $sortName, $excludedFields);
        }

        $cacheKey = $this->redis->makeKey('brands_get_list', [$filter, $sortName, $excludedFields]);
        $cached = $this->redis->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $brands = parent::getList($filter, $sortName, $excludedFields);
        $this->redis->set($cacheKey, $brands, $this->redis->getHelperTtl('brands_get_list'));
        return $brands;
    }
}

