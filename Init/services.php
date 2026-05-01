<?php

namespace Okay\Modules\Sviat\Redis\Init;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Money;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\Request;
use Okay\Core\Router;
use Okay\Core\Settings;
use Okay\Helpers\AuthorsHelper as CoreAuthorsHelper;
use Okay\Helpers\BlogHelper as CoreBlogHelper;
use Okay\Helpers\BrandsHelper as CoreBrandsHelper;
use Okay\Helpers\CatalogHelper as CoreCatalogHelper;
use Okay\Helpers\CategoriesHelper as CoreCategoriesHelper;
use Okay\Helpers\FilterHelper as CoreFilterHelper;
use Okay\Helpers\MetaRobotsHelper;
use Okay\Helpers\MetadataHelpers\ProductMetadataHelper;
use Okay\Helpers\MoneyHelper;
use Okay\Helpers\ProductsHelper as CoreProductsHelper;
use Okay\Helpers\RelatedProductsHelper as CoreRelatedProductsHelper;
use Okay\Modules\Sviat\Redis\Backend\Controllers\RedisSettingsAdmin;
use Okay\Modules\Sviat\Redis\Extenders\AuthorCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\BlogCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\BlogRelatedCacheExtender;
use Okay\Modules\Sviat\Redis\Extenders\BrandCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\CategoryCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\CurrencyCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\FeaturesCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\FeaturesValuesCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\ImagesCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\ProductCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\SpecialImagesCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\VariantsCacheInvalidator;
use Okay\Modules\Sviat\Redis\Helpers\AuthorsHelper;
use Okay\Modules\Sviat\Redis\Helpers\BlogHelper;
use Okay\Modules\Sviat\Redis\Helpers\BrandsHelper;
use Okay\Modules\Sviat\Redis\Helpers\CatalogHelper;
use Okay\Modules\Sviat\Redis\Helpers\CategoriesHelper;
use Okay\Modules\Sviat\Redis\Helpers\FilterHelper;
use Okay\Modules\Sviat\Redis\Helpers\MoneyHelper as RedisMoneyHelper;
use Okay\Modules\Sviat\Redis\Helpers\ProductsHelper;
use Okay\Modules\Sviat\Redis\Helpers\RelatedProductsHelper;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

return [
    RedisCacheService::class => [
        'class' => RedisCacheService::class,
        'arguments' => [new SR(Settings::class)],
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
        'calls' => [['method' => 'init']],
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

    MoneyHelper::class => [
        'class' => RedisMoneyHelper::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Settings::class),
            new SR(RedisCacheService::class),
        ],
    ],

    // ----- Invalidators -----
    VariantsCacheInvalidator::class => [
        'class' => VariantsCacheInvalidator::class,
        'arguments' => [new SR(EntityFactory::class), new SR(RedisCacheService::class)],
    ],
    BlogRelatedCacheExtender::class => [
        'class' => BlogRelatedCacheExtender::class,
        'arguments' => [new SR(RedisCacheService::class)],
    ],
    ProductCacheInvalidator::class => [
        'class' => ProductCacheInvalidator::class,
        'arguments' => [new SR(RedisCacheService::class)],
    ],
    CategoryCacheInvalidator::class => [
        'class' => CategoryCacheInvalidator::class,
        'arguments' => [new SR(RedisCacheService::class)],
    ],
    BrandCacheInvalidator::class => [
        'class' => BrandCacheInvalidator::class,
        'arguments' => [new SR(RedisCacheService::class)],
    ],
    BlogCacheInvalidator::class => [
        'class' => BlogCacheInvalidator::class,
        'arguments' => [new SR(RedisCacheService::class)],
    ],
    AuthorCacheInvalidator::class => [
        'class' => AuthorCacheInvalidator::class,
        'arguments' => [new SR(RedisCacheService::class)],
    ],
    CurrencyCacheInvalidator::class => [
        'class' => CurrencyCacheInvalidator::class,
        'arguments' => [new SR(RedisCacheService::class)],
    ],
    ImagesCacheInvalidator::class => [
        'class' => ImagesCacheInvalidator::class,
        'arguments' => [new SR(RedisCacheService::class)],
    ],
    SpecialImagesCacheInvalidator::class => [
        'class' => SpecialImagesCacheInvalidator::class,
        'arguments' => [new SR(RedisCacheService::class)],
    ],
    FeaturesCacheInvalidator::class => [
        'class' => FeaturesCacheInvalidator::class,
        'arguments' => [new SR(RedisCacheService::class)],
    ],
    FeaturesValuesCacheInvalidator::class => [
        'class' => FeaturesValuesCacheInvalidator::class,
        'arguments' => [new SR(RedisCacheService::class)],
    ],

    RedisSettingsAdmin::class => [
        'class' => RedisSettingsAdmin::class,
        'arguments' => [],
    ],
];
