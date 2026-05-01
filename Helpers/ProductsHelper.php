<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Settings;
use Okay\Helpers\CatalogHelper;
use Okay\Helpers\FilterHelper;
use Okay\Helpers\MetadataHelpers\ProductMetadataHelper;
use Okay\Helpers\MoneyHelper;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class ProductsHelper extends \Okay\Helpers\ProductsHelper
{
    private RedisCacheService $redisCache;

    public function __construct(
        EntityFactory         $entityFactory,
        MoneyHelper           $moneyHelper,
        Settings              $settings,
        ProductMetadataHelper $productMetadataHelper,
        CatalogHelper         $catalogHelper,
        FilterHelper          $filterHelper,
        RedisCacheService     $redisCache
    ) {
        parent::__construct($entityFactory, $moneyHelper, $settings, $productMetadataHelper, $catalogHelper, $filterHelper);
        $this->redisCache = $redisCache;
    }

    public function attachProductData($product)
    {
        if (empty($product->id)) {
            return parent::attachProductData($product);
        }
        if (!$this->redisCache->isEnabled()) {
            return parent::attachProductData($product);
        }

        $productId = (int) $product->id;
        $tags = [CacheTags::product($productId), CacheTags::PRODUCTS_ALL];

        $variantsKey = $this->redisCache->makeVersionedKey('product_attach_variants', $tags, [$productId]);
        $imagesKey   = $this->redisCache->makeVersionedKey('product_attach_images',   $tags, [$productId]);
        $featuresKey = $this->redisCache->makeVersionedKey('product_attach_features', $tags, [$productId]);

        $cached = $this->redisCache->mGet([$variantsKey, $imagesKey, $featuresKey]);

        // Variants
        $variantsCached = $cached[$variantsKey] ?? null;
        if (is_array($variantsCached) && \array_key_exists('variants', $variantsCached)) {
            $product->variants = $variantsCached['variants'];
            if (\array_key_exists('variant', $variantsCached)) {
                $product->variant = $variantsCached['variant'];
            }
        } else {
            $tmp = [$productId => $product];
            $tmp = parent::attachVariants($tmp);
            $product = reset($tmp);
            $ttl = $this->redisCache->getHelperTtl('product_attach_variants') ?? 300;
            $this->redisCache->set($variantsKey, [
                'variants' => $product->variants ?? null,
                'variant'  => $product->variant ?? null,
            ], $ttl);
        }

        // Images
        $imagesCached = $cached[$imagesKey] ?? null;
        if (is_array($imagesCached)) {
            if (\array_key_exists('images', $imagesCached)) { $product->images = $imagesCached['images']; }
            if (\array_key_exists('image',  $imagesCached)) { $product->image  = $imagesCached['image']; }
        } else {
            $tmp = [$productId => $product];
            $tmp = $this->attachImages($tmp);
            $product = reset($tmp);
            $ttl = $this->redisCache->getHelperTtl('product_attach_images') ?? 3600;
            $this->redisCache->set($imagesKey, [
                'images' => $product->images ?? null,
                'image'  => $product->image ?? null,
            ], $ttl);
        }

        // Features
        $featuresCached = $cached[$featuresKey] ?? null;
        if ($featuresCached !== null) {
            $product->features = $featuresCached;
        } else {
            $tmp = [$productId => $product];
            $tmp = $this->attachFeatures($tmp);
            $product = reset($tmp);
            $ttl = $this->redisCache->getHelperTtl('product_attach_features') ?? 3600;
            $this->redisCache->set($featuresKey, $product->features ?? null, $ttl);
        }

        return ExtenderFacade::execute(
            \Okay\Helpers\ProductsHelper::class . '::attachProductData',
            $product,
            func_get_args()
        );
    }

    public function attachImages(array $products)
    {
        if (empty($products) || !$this->redisCache->isEnabled()) {
            return parent::attachImages($products);
        }
        $productIds = array_map('intval', array_keys($products));
        sort($productIds);
        if (count($productIds) > 20) {
            return parent::attachImages($products);
        }
        $tags = [CacheTags::PRODUCTS_ALL];
        $key = $this->redisCache->makeVersionedKey('products_attach_images', $tags, [$productIds]);
        $cached = $this->redisCache->get($key);

        if (is_array($cached)) {
            foreach ($products as $pid => $p) {
                $pid = (int) $pid;
                if (isset($cached[$pid])) {
                    $p->images = $cached[$pid]['images'] ?? null;
                    $p->image  = $cached[$pid]['image']  ?? null;
                }
            }
            return ExtenderFacade::execute(
                \Okay\Helpers\ProductsHelper::class . '::attachImages',
                $products,
                func_get_args()
            );
        }

        $result = parent::attachImages($products);
        $payload = [];
        foreach ($result as $pid => $p) {
            $payload[(int) $pid] = ['images' => $p->images ?? null, 'image' => $p->image ?? null];
        }
        $ttl = $this->redisCache->getHelperTtl('products_attach_images') ?? 3600;
        $this->redisCache->set($key, $payload, $ttl);
        return $result;
    }

    public function attachMainImages(array $products)
    {
        if (empty($products) || !$this->redisCache->isEnabled()) {
            return parent::attachMainImages($products);
        }
        $imageIds = [];
        foreach ($products as $p) {
            if (!empty($p->main_image_id)) {
                $imageIds[] = (int) $p->main_image_id;
            }
        }
        $imageIds = array_values(array_unique($imageIds));
        sort($imageIds);
        if (count($imageIds) > 50) {
            return parent::attachMainImages($products);
        }
        $tags = [CacheTags::PRODUCTS_ALL];
        $key = $this->redisCache->makeVersionedKey('products_attach_main_images', $tags, [$imageIds]);
        $cached = $this->redisCache->get($key);

        if (is_array($cached)) {
            foreach ($products as $p) {
                $mid = !empty($p->main_image_id) ? (int) $p->main_image_id : 0;
                if ($mid && isset($cached[$mid])) {
                    $p->image = $cached[$mid];
                }
            }
            return ExtenderFacade::execute(
                \Okay\Helpers\ProductsHelper::class . '::attachMainImages',
                $products,
                func_get_args()
            );
        }

        $result = parent::attachMainImages($products);
        $payload = [];
        foreach ($result as $p) {
            if (!empty($p->image) && !empty($p->image->id)) {
                $payload[(int) $p->image->id] = $p->image;
            }
        }
        $ttl = $this->redisCache->getHelperTtl('products_attach_main_images') ?? 3600;
        $this->redisCache->set($key, $payload, $ttl);
        return $result;
    }

    public function getList($filter = [], $sortName = null, $excludedFields = null)
    {
        if (!$this->redisCache->isEnabled()) {
            return parent::getList($filter, $sortName, $excludedFields);
        }
        $tags = [CacheTags::PRODUCTS_LIST, CacheTags::PRODUCTS_ALL];
        $key  = $this->redisCache->makeVersionedKey('products_get_list', $tags, [$filter, $sortName, $excludedFields]);
        $cached = $this->redisCache->get($key);
        if ($cached !== null) {
            return ExtenderFacade::execute(
                \Okay\Helpers\ProductsHelper::class . '::getList',
                $cached,
                func_get_args()
            );
        }
        $result = parent::getList($filter, $sortName, $excludedFields);
        if (!empty($result)) {
            $ttl = $this->redisCache->getHelperTtl('products_get_list');
            $this->redisCache->set($key, $result, $ttl);
        }
        return $result;
    }
}
