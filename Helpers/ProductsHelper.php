<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Settings;
use Okay\Helpers\CatalogHelper;
use Okay\Helpers\FilterHelper;
use Okay\Helpers\MetadataHelpers\ProductMetadataHelper;
use Okay\Helpers\MoneyHelper;
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
        parent::__construct(
            $entityFactory,
            $moneyHelper,
            $settings,
            $productMetadataHelper,
            $catalogHelper,
            $filterHelper
        );
        $this->redisCache = $redisCache;
    }

    public function attachProductData($product)
    {
        if (empty($product->id)) {
            return parent::attachProductData($product);
        }

        // Variants cache key includes invalidation versions.
        $productId = (int) $product->id;
        $variantsKey = null;
        if ($this->redisCache->canCache('product_attach_variants')) {
            $pv = $this->redisCache->getProductVariantsVersion($productId);
            $gv = $this->redisCache->getGlobalVariantsVersion();
            $variantsKey = $this->redisCache->makeKey('product_attach_variants', [$productId, $pv, $gv]);
        }

        $imagesKey = $this->redisCache->canCache('product_attach_images')
            ? $this->redisCache->makeKey('product_attach_images', [$productId])
            : null;

        $featuresKey = $this->redisCache->canCache('product_attach_features')
            ? $this->redisCache->makeKey('product_attach_features', [$productId])
            : null;

        // Fetch all product-level cache keys in one request.
        $keys = array_values(array_filter([$variantsKey, $imagesKey, $featuresKey]));
        $cachedMap = $keys !== [] ? $this->redisCache->mGet($keys) : [];

        // Apply variants.
        $variantsCached = $variantsKey !== null ? ($cachedMap[$variantsKey] ?? null) : null;
        if (is_array($variantsCached) && array_key_exists('variants', $variantsCached)) {
            $product->variants = $variantsCached['variants'];
            if (array_key_exists('variant', $variantsCached)) {
                $product->variant = $variantsCached['variant'];
            }
        } else {
            $products = [$productId => $product];
            /** @var array $products */
            $products = parent::attachVariants($products);
            $product = reset($products);

            if ($variantsKey !== null) {
                $ttl = $this->redisCache->getHelperTtl('product_attach_variants') ?? 300;
                $this->redisCache->set($variantsKey, [
                    'variants' => $product->variants ?? null,
                    'variant'  => $product->variant ?? null,
                ], $ttl);
            }
        }

        // Apply images.
        $imagesCached = $imagesKey !== null ? ($cachedMap[$imagesKey] ?? null) : null;
        if (is_array($imagesCached)) {
            if (array_key_exists('images', $imagesCached)) {
                $product->images = $imagesCached['images'];
            }
            if (array_key_exists('image', $imagesCached)) {
                $product->image = $imagesCached['image'];
            }
        } else {
            $tmp = [$productId => $product];
            $tmp = $this->attachImages($tmp);
            $product = reset($tmp);

            if ($imagesKey !== null) {
                $ttl = $this->redisCache->getHelperTtl('product_attach_images') ?? 3600;
                $this->redisCache->set($imagesKey, [
                    'images' => $product->images ?? null,
                    'image'  => $product->image ?? null,
                ], $ttl);
            }
        }

        // Apply features.
        $featuresCached = $featuresKey !== null ? ($cachedMap[$featuresKey] ?? null) : null;
        if ($featuresCached !== null) {
            $product->features = $featuresCached;
        } else {
            $tmp = [$productId => $product];
            $tmp = $this->attachFeatures($tmp);
            $product = reset($tmp);

            if ($featuresKey !== null) {
                $ttl = $this->redisCache->getHelperTtl('product_attach_features') ?? 3600;
                $this->redisCache->set($featuresKey, $product->features ?? null, $ttl);
            }
        }

        // Fire extender chain registered on the base helper method so module
        // extenders (DPB, Promo ...) get to enrich the
        // product on single-product pages — same as the parent's tail call.
        return ExtenderFacade::execute(
            \Okay\Helpers\ProductsHelper::class . '::attachProductData',
            $product,
            func_get_args()
        );
    }

    public function attachImages(array $products)
    {
        if (empty($products)) {
            return parent::attachImages($products);
        }

        if (!$this->redisCache->canCache('products_attach_images')) {
            return parent::attachImages($products);
        }

        $productIds = array_map('intval', array_keys($products));
        sort($productIds);

        // Avoid very large key combinations.
        if (count($productIds) > 20) {
            return parent::attachImages($products);
        }

        $key = $this->redisCache->makeKey('products_attach_images', [$productIds]);
        $cached = $this->redisCache->get($key);
        if (is_array($cached)) {
            foreach ($products as $pid => $p) {
                $pid = (int) $pid;
                if (isset($cached[$pid])) {
                    $p->images = $cached[$pid]['images'] ?? null;
                    $p->image = $cached[$pid]['image'] ?? null;
                }
            }
            return $products;
        }

        $result = parent::attachImages($products);

        $payload = [];
        foreach ($result as $pid => $p) {
            $pid = (int) $pid;
            $payload[$pid] = [
                'images' => $p->images ?? null,
                'image'  => $p->image ?? null,
            ];
        }

        $ttl = $this->redisCache->getHelperTtl('products_attach_images') ?? 3600;
        $this->redisCache->set($key, $payload, $ttl);

        return $result;
    }

    public function attachMainImages(array $products)
    {
        if (empty($products)) {
            return parent::attachMainImages($products);
        }

        if (!$this->redisCache->canCache('products_attach_main_images')) {
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

        $key = $this->redisCache->makeKey('products_attach_main_images', [$imageIds]);
        $cached = $this->redisCache->get($key);
        if (is_array($cached)) {
            foreach ($products as $p) {
                $mid = !empty($p->main_image_id) ? (int) $p->main_image_id : 0;
                if ($mid && isset($cached[$mid])) {
                    $p->image = $cached[$mid];
                }
            }
            return $products;
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
        if (!$this->redisCache->canCache('products_get_list')) {
            return parent::getList($filter, $sortName, $excludedFields);
        }

        $ttl = $this->redisCache->getHelperTtl('products_get_list');
        $key = $this->redisCache->makeKey('products_get_list', [$filter, $sortName, $excludedFields]);

        $cached = $this->redisCache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $result = parent::getList($filter, $sortName, $excludedFields);
        if (!empty($result)) {
            $this->redisCache->set($key, $result, $ttl);
        }

        return $result;
    }
}

