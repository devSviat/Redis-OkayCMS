<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Request;
use Okay\Core\Router;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class FilterHelper extends \Okay\Helpers\FilterHelper
{
    private RedisCacheService $redis;

    public function __construct(
        EntityFactory     $entityFactory,
        Settings          $settings,
        Request           $request,
        Router            $router,
        Design            $design,
        FrontTranslations $frontTranslations,
        RedisCacheService $redis
    ) {
        parent::__construct($entityFactory, $settings, $request, $router, $design, $frontTranslations);
        $this->redis = $redis;
    }

    public function getBrands(array $brandsFilter): array
    {
        if (!$this->redis->canCache('filter_get_brands')) {
            return parent::getBrands($brandsFilter);
        }

        $cacheKey = $this->redis->makeKey('filter_get_brands', [$brandsFilter]);
        $cached = $this->redis->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $brands = parent::getBrands($brandsFilter);
        $this->redis->set($cacheKey, $brands, $this->redis->getHelperTtl('filter_get_brands'));
        return $brands;
    }
}

