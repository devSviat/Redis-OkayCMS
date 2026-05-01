<?php

namespace Okay\Modules\Sviat\Redis\Init;

use Okay\Core\Modules\AbstractInit;
use Okay\Entities\AuthorsEntity;
use Okay\Entities\BlogEntity;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\FeaturesEntity;
use Okay\Entities\FeaturesValuesEntity;
use Okay\Entities\ImagesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\SpecialImagesEntity;
use Okay\Entities\VariantsEntity;
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

        // Blog related products — ChainExtender (returns the cached value).
        $this->registerChainExtension(
            ['class' => BlogEntity::class, 'method' => 'getRelatedProducts'],
            ['class' => BlogRelatedCacheExtender::class, 'method' => 'getRelatedProducts']
        );

        // Products
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

        // Categories
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

        // Brands
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

        // Blog
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

        // Authors
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

        // Currencies
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

        // Variants
        $this->registerQueueExtension(
            ['class' => VariantsEntity::class, 'method' => 'update'],
            ['class' => VariantsCacheInvalidator::class, 'method' => 'onVariantsUpdate']
        );
        $this->registerQueueExtension(
            ['class' => VariantsEntity::class, 'method' => 'add'],
            ['class' => VariantsCacheInvalidator::class, 'method' => 'onVariantsAdd']
        );
        $this->registerQueueExtension(
            ['class' => VariantsEntity::class, 'method' => 'delete'],
            ['class' => VariantsCacheInvalidator::class, 'method' => 'onVariantsDelete']
        );

        // Images (NEW — fixes the stale-image bug)
        $this->registerQueueExtension(
            ['class' => ImagesEntity::class, 'method' => 'add'],
            ['class' => ImagesCacheInvalidator::class, 'method' => 'onImageAdd']
        );
        $this->registerQueueExtension(
            ['class' => ImagesEntity::class, 'method' => 'update'],
            ['class' => ImagesCacheInvalidator::class, 'method' => 'onImageUpdate']
        );
        $this->registerQueueExtension(
            ['class' => ImagesEntity::class, 'method' => 'delete'],
            ['class' => ImagesCacheInvalidator::class, 'method' => 'onImageDelete']
        );

        // Special Images (NEW)
        $this->registerQueueExtension(
            ['class' => SpecialImagesEntity::class, 'method' => 'add'],
            ['class' => SpecialImagesCacheInvalidator::class, 'method' => 'onSpecialImageAdd']
        );
        $this->registerQueueExtension(
            ['class' => SpecialImagesEntity::class, 'method' => 'update'],
            ['class' => SpecialImagesCacheInvalidator::class, 'method' => 'onSpecialImageUpdate']
        );
        $this->registerQueueExtension(
            ['class' => SpecialImagesEntity::class, 'method' => 'delete'],
            ['class' => SpecialImagesCacheInvalidator::class, 'method' => 'onSpecialImageDelete']
        );

        // Features (NEW)
        $this->registerQueueExtension(
            ['class' => FeaturesEntity::class, 'method' => 'add'],
            ['class' => FeaturesCacheInvalidator::class, 'method' => 'onFeatureAdd']
        );
        $this->registerQueueExtension(
            ['class' => FeaturesEntity::class, 'method' => 'update'],
            ['class' => FeaturesCacheInvalidator::class, 'method' => 'onFeatureUpdate']
        );
        $this->registerQueueExtension(
            ['class' => FeaturesEntity::class, 'method' => 'delete'],
            ['class' => FeaturesCacheInvalidator::class, 'method' => 'onFeatureDelete']
        );

        // Features Values (NEW)
        $this->registerQueueExtension(
            ['class' => FeaturesValuesEntity::class, 'method' => 'add'],
            ['class' => FeaturesValuesCacheInvalidator::class, 'method' => 'onFeatureValueAdd']
        );
        $this->registerQueueExtension(
            ['class' => FeaturesValuesEntity::class, 'method' => 'update'],
            ['class' => FeaturesValuesCacheInvalidator::class, 'method' => 'onFeatureValueUpdate']
        );
        $this->registerQueueExtension(
            ['class' => FeaturesValuesEntity::class, 'method' => 'delete'],
            ['class' => FeaturesValuesCacheInvalidator::class, 'method' => 'onFeatureValueDelete']
        );
    }
}
