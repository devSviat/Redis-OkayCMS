<?php

namespace Okay\Modules\Sviat\Redis\Init;

use Okay\Core\Modules\AbstractInit;
use Okay\Entities\BlogEntity;
use Okay\Entities\VariantsEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\BrandsEntity;
use Okay\Entities\AuthorsEntity;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Redis\Extenders\BlogRelatedCacheExtender;
use Okay\Modules\Sviat\Redis\Extenders\VariantsCacheExtender;
use Okay\Modules\Sviat\Redis\Extenders\ProductCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\CategoryCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\BrandCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\BlogCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\AuthorCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\CurrencyCacheInvalidator;

class Init extends AbstractInit
{
    public function install()
    {
        $this->setBackendMainController('RedisSettingsAdmin');
    }

    public function init()
    {
        $this->registerBackendController('RedisSettingsAdmin');
        $this->addBackendControllerPermission('RedisSettingsAdmin', 'settings');

        // ============================================================
        // CACHE CHAIN EXTENSIONS
        // ============================================================

        // Cache related blog products.
        $this->registerChainExtension(
            ['class' => BlogEntity::class, 'method' => 'getRelatedProducts'],
            ['class' => BlogRelatedCacheExtender::class, 'method' => 'getRelatedProducts']
        );

        // ============================================================
        // CACHE INVALIDATION EXTENSIONS (Queue = Side Effects)
        // ============================================================

        // Product cache invalidation on changes
        $this->registerQueueExtension(
            ['class' => ProductsEntity::class, 'method' => 'update'],
            ['class' => ProductCacheInvalidator::class, 'method' => 'onProductUpdate']
        );
        $this->registerQueueExtension(
            ['class' => ProductsEntity::class, 'method' => 'add'],
            ['class' => ProductCacheInvalidator::class, 'method' => 'onProductAdd']
        );
        $this->registerQueueExtension(
            ['class' => ProductsEntity::class, 'method' => 'delete'],
            ['class' => ProductCacheInvalidator::class, 'method' => 'onProductDelete']
        );

        // Category cache invalidation on changes
        $this->registerQueueExtension(
            ['class' => CategoriesEntity::class, 'method' => 'update'],
            ['class' => CategoryCacheInvalidator::class, 'method' => 'onCategoryUpdate']
        );
        $this->registerQueueExtension(
            ['class' => CategoriesEntity::class, 'method' => 'add'],
            ['class' => CategoryCacheInvalidator::class, 'method' => 'onCategoryAdd']
        );
        $this->registerQueueExtension(
            ['class' => CategoriesEntity::class, 'method' => 'delete'],
            ['class' => CategoryCacheInvalidator::class, 'method' => 'onCategoryDelete']
        );

        // Brand cache invalidation on changes
        $this->registerQueueExtension(
            ['class' => BrandsEntity::class, 'method' => 'update'],
            ['class' => BrandCacheInvalidator::class, 'method' => 'onBrandUpdate']
        );
        $this->registerQueueExtension(
            ['class' => BrandsEntity::class, 'method' => 'add'],
            ['class' => BrandCacheInvalidator::class, 'method' => 'onBrandAdd']
        );
        $this->registerQueueExtension(
            ['class' => BrandsEntity::class, 'method' => 'delete'],
            ['class' => BrandCacheInvalidator::class, 'method' => 'onBrandDelete']
        );

        // Blog cache invalidation on changes
        $this->registerQueueExtension(
            ['class' => BlogEntity::class, 'method' => 'update'],
            ['class' => BlogCacheInvalidator::class, 'method' => 'onBlogUpdate']
        );
        $this->registerQueueExtension(
            ['class' => BlogEntity::class, 'method' => 'add'],
            ['class' => BlogCacheInvalidator::class, 'method' => 'onBlogAdd']
        );
        $this->registerQueueExtension(
            ['class' => BlogEntity::class, 'method' => 'delete'],
            ['class' => BlogCacheInvalidator::class, 'method' => 'onBlogDelete']
        );

        // Author cache invalidation on changes
        $this->registerQueueExtension(
            ['class' => AuthorsEntity::class, 'method' => 'update'],
            ['class' => AuthorCacheInvalidator::class, 'method' => 'onAuthorUpdate']
        );
        $this->registerQueueExtension(
            ['class' => AuthorsEntity::class, 'method' => 'add'],
            ['class' => AuthorCacheInvalidator::class, 'method' => 'onAuthorAdd']
        );
        $this->registerQueueExtension(
            ['class' => AuthorsEntity::class, 'method' => 'delete'],
            ['class' => AuthorCacheInvalidator::class, 'method' => 'onAuthorDelete']
        );

        // Currency cache invalidation on changes
        $this->registerQueueExtension(
            ['class' => CurrenciesEntity::class, 'method' => 'update'],
            ['class' => CurrencyCacheInvalidator::class, 'method' => 'onCurrencyUpdate']
        );
        $this->registerQueueExtension(
            ['class' => CurrenciesEntity::class, 'method' => 'add'],
            ['class' => CurrencyCacheInvalidator::class, 'method' => 'onCurrencyAdd']
        );
        $this->registerQueueExtension(
            ['class' => CurrenciesEntity::class, 'method' => 'delete'],
            ['class' => CurrencyCacheInvalidator::class, 'method' => 'onCurrencyDelete']
        );

        // Variants cache invalidation on changes (existing)
        $this->registerQueueExtension(
            ['class' => VariantsEntity::class, 'method' => 'update'],
            ['class' => VariantsCacheExtender::class, 'method' => 'onVariantsUpdate']
        );
        $this->registerQueueExtension(
            ['class' => VariantsEntity::class, 'method' => 'add'],
            ['class' => VariantsCacheExtender::class, 'method' => 'onVariantsAdd']
        );
        $this->registerQueueExtension(
            ['class' => VariantsEntity::class, 'method' => 'delete'],
            ['class' => VariantsCacheExtender::class, 'method' => 'onVariantsDelete']
        );
    }
}

