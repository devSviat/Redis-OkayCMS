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
        if (!$this->redis->isEnabled()) {
            return parent::getList($filter, $sortName, $excludedFields);
        }
        $key = $this->redis->makeVersionedKey('brands_get_list', [CacheTags::BRANDS], [$filter, $sortName, $excludedFields]);
        $cached = $this->redis->get($key);
        if (is_array($cached)) {
            return ExtenderFacade::execute(
                \Okay\Helpers\BrandsHelper::class . '::getList',
                $cached,
                func_get_args()
            );
        }
        $brands = parent::getList($filter, $sortName, $excludedFields);
        $this->redis->set($key, $brands, $this->redis->getHelperTtl('brands_get_list'));
        return $brands;
    }
}
