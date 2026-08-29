<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Routes\ProductRoute;
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
        // Аргументи знімаємо до роботи: далі $product перепризначається, а
        // func_get_args() з PHP 7.0 віддає поточне значення, не вихідне.
        $args = func_get_args();

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
            // parent, а не self: перевизначений attachImages поклав би ті самі
            // дані ще й під власним ключем, який на цьому шляху ніхто не читає.
            $tmp = [$productId => $product];
            $tmp = parent::attachImages($tmp);
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
            $args
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
        $tags = $this->imageCacheTags($productIds);
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
        // Вартість ключа тягне не лише кількість картинок: тегів рівно стільки,
        // скільки товарів, і кожен — це версія в mGET. Лістинг на 200 товарів
        // із 40 картинками інакше пройшов би повз обмеження і зібрав ключ на
        // 201 тег.
        if (count($imageIds) > 50 || count($products) > 50) {
            return parent::attachMainImages($products);
        }
        $tags = $this->imageCacheTags(array_map('intval', array_keys($products)));
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
        $args = func_get_args();

        return $this->redisCache->remember(
            'products_get_list',
            [CacheTags::PRODUCTS_LIST, CacheTags::PRODUCTS_ALL],
            [$filter, $sortName, $excludedFields],
            fn () => parent::getList($filter, $sortName, $excludedFields),
            $this->redisCache->getHelperTtl('products_get_list'),
            function ($cached) use ($args) {
                $this->warmRouteAliases($cached);

                return ExtenderFacade::execute(
                    \Okay\Helpers\ProductsHelper::class . '::getList',
                    $cached,
                    $args
                );
            },
            // Порожню вибірку не кешуємо: у пошуку кожна фраза дає свій ключ,
            // а розширення однаково перезапитує за порожнім результатом — тобто
            // сотні ключів без користі.
            static fn ($result) => !empty($result)
        );
    }

    /**
     * Ядро робить це в кінці Okay\Helpers\ProductsHelper::getList(). На HIT ми
     * туди не заходимо, і перший {$product|url} у шаблоні змушує
     * NoPrefixAndCategoryStrategy вичитати всю ok_router_cache — заміряно
     * 18448 прочитаних рядків проти 2901 на MISS, тобто HIT виходив
     * повільнішим за MISS.
     */
    private function warmRouteAliases($products): void
    {
        if (!is_array($products)) {
            return;
        }
        foreach ($products as $product) {
            if (!empty($product->url) && !empty($product->slug_url)) {
                ProductRoute::setUrlSlugAlias($product->url, $product->slug_url);
            }
        }
    }

    /**
     * Ключі картинок теговані PRODUCTS_ALL, а ImagesCacheInvalidator бампає
     * pver:<id> — теги не збігалися, тож інвалідація списків була no-op і
     * стара картинка жила до кінця TTL.
     */
    private function imageCacheTags(array $productIds): array
    {
        // Сортуємо: понад чотири теги вектор версій згортається в md5, тож
        // порядок став би значущим і той самий набір товарів під різним
        // сортуванням лістингу давав би різні ключі.
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        sort($productIds);

        $tags = [CacheTags::PRODUCTS_ALL];
        foreach ($productIds as $id) {
            $tags[] = CacheTags::product($id);
        }
        return $tags;
    }
}
