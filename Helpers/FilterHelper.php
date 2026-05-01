<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Request;
use Okay\Core\Router;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
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
        if (!$this->redis->isEnabled()) {
            return parent::getBrands($brandsFilter);
        }
        $key = $this->redis->makeVersionedKey('filter_get_brands', [CacheTags::BRANDS, CacheTags::PRODUCTS_LIST], [$brandsFilter]);
        $cached = $this->redis->get($key);
        if (is_array($cached)) {
            return ExtenderFacade::execute(
                \Okay\Helpers\FilterHelper::class . '::getBrands',
                $cached,
                func_get_args()
            );
        }
        $brands = parent::getBrands($brandsFilter);
        $this->redis->set($key, $brands, $this->redis->getHelperTtl('filter_get_brands'));
        return $brands;
    }
}
