<?php

namespace Okay\Modules\Sviat\Redis\Init;

use Okay\Core\EntityFactory;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\Request;
use Okay\Core\Settings;
use Okay\Core\Money;
use Okay\Core\Design;
use Okay\Core\FrontTranslations;
use Okay\Core\Router;

use Okay\Helpers\ProductsHelper as CoreProductsHelper;
use Okay\Helpers\CatalogHelper as CoreCatalogHelper;
use Okay\Helpers\CategoriesHelper as CoreCategoriesHelper;
use Okay\Helpers\BrandsHelper as CoreBrandsHelper;
use Okay\Helpers\FilterHelper as CoreFilterHelper;
use Okay\Helpers\MoneyHelper;
use Okay\Modules\Sviat\Redis\Helpers\MoneyHelper as RedisMoneyHelper;
use Okay\Helpers\AuthorsHelper as CoreAuthorsHelper;
use Okay\Helpers\BlogHelper as CoreBlogHelper;
use Okay\Helpers\RelatedProductsHelper as CoreRelatedProductsHelper;
use Okay\Helpers\MetadataHelpers\ProductMetadataHelper;
use Okay\Helpers\MetaRobotsHelper;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use Okay\Modules\Sviat\Redis\Helpers\ProductsHelper;
use Okay\Modules\Sviat\Redis\Helpers\CatalogHelper;
use Okay\Modules\Sviat\Redis\Helpers\CategoriesHelper;
use Okay\Modules\Sviat\Redis\Helpers\BrandsHelper;
use Okay\Modules\Sviat\Redis\Helpers\FilterHelper;
use Okay\Modules\Sviat\Redis\Helpers\AuthorsHelper;
use Okay\Modules\Sviat\Redis\Helpers\BlogHelper;
use Okay\Modules\Sviat\Redis\Helpers\RelatedProductsHelper;
use Okay\Modules\Sviat\Redis\Backend\Controllers\RedisSettingsAdmin;
use Okay\Modules\Sviat\Redis\Extenders\VariantsCacheExtender;
use Okay\Modules\Sviat\Redis\Extenders\BlogRelatedCacheExtender;

return [
    RedisCacheService::class => [
        'class' => RedisCacheService::class,
        'arguments' => [
            new SR(Settings::class),
        ],
    ],

    CoreProductsHelper::class => [
        'class' => ProductsHelper::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(MoneyHelper::class),
            new SR(Settings::class),
            new SR(ProductMetadataHelper::class),
            new SR(CoreCatalogHelper::class),
            new SR(CoreFilterHelper::class),
            new SR(RedisCacheService::class),
        ],
    ],

    CoreCatalogHelper::class => [
        'class' => CatalogHelper::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Money::class),
            new SR(Settings::class),
            new SR(Request::class),
            new SR(CoreFilterHelper::class),
            new SR(MetaRobotsHelper::class),
            new SR(Design::class),
            new SR(RedisCacheService::class),
        ],
    ],

    CoreCategoriesHelper::class => [
        'class' => CategoriesHelper::class,
        'arguments' => [
            new SR(CoreCatalogHelper::class),
            new SR(EntityFactory::class),
            new SR(Settings::class),
            new SR(Design::class),
            new SR(CoreFilterHelper::class),
            new SR(RedisCacheService::class),
        ],
    ],

    CoreFilterHelper::class => [
        'class' => FilterHelper::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Settings::class),
            new SR(Request::class),
            new SR(Router::class),
            new SR(Design::class),
            new SR(FrontTranslations::class),
            new SR(RedisCacheService::class),
        ],
        'calls' => [
            [
                'method' => 'init',
            ],
        ],
    ],

    CoreBrandsHelper::class => [
        'class' => BrandsHelper::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(CoreCatalogHelper::class),
            new SR(Settings::class),
            new SR(CoreFilterHelper::class),
            new SR(Design::class),
            new SR(RedisCacheService::class),
        ],
    ],

    CoreAuthorsHelper::class => [
        'class' => AuthorsHelper::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(RedisCacheService::class),
        ],
    ],

    CoreBlogHelper::class => [
        'class' => BlogHelper::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(CoreAuthorsHelper::class),
            new SR(RedisCacheService::class),
        ],
    ],

    CoreRelatedProductsHelper::class => [
        'class' => RelatedProductsHelper::class,
        'arguments' => [
            new SR(CoreProductsHelper::class),
            new SR(RedisCacheService::class),
        ],
    ],

    VariantsCacheExtender::class => [
        'class' => VariantsCacheExtender::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(RedisCacheService::class),
        ],
    ],

    BlogRelatedCacheExtender::class => [
        'class' => BlogRelatedCacheExtender::class,
        'arguments' => [
            new SR(RedisCacheService::class),
        ],
    ],

    MoneyHelper::class => [
        'class' => RedisMoneyHelper::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Settings::class),
            new SR(RedisCacheService::class),
        ],
    ],

    RedisSettingsAdmin::class => [
        'class' => RedisSettingsAdmin::class,
        'arguments' => [],
    ],
];

