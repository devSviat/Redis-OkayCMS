<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Routes\AbstractRoute;
use Okay\Core\Routes\ProductRoute;
use Okay\Modules\Sviat\Redis\Helpers\ProductsHelper;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StubRedisCacheService.php';
require_once __DIR__ . '/PrivateAccess.php';

/**
 * Регресія: на HIT ми не заходимо в parent::getList(), а саме там ядро
 * (Okay/Helpers/ProductsHelper::getList) наповнює мапу url→slug. Без неї
 * перший {$product|url} у шаблоні змушує NoPrefixAndCategoryStrategy
 * вичитати всю ok_router_cache — заміряно 18448 рядків проти 2901 на MISS,
 * через що HIT виходив повільнішим за MISS.
 */
class ProductsHelperCacheTest extends TestCase
{
    use PrivateAccess;

    protected function setUp(): void
    {
        $prop = self::accessible(new \ReflectionProperty(AbstractRoute::class, 'routeAliases'));
        $prop->setValue(null, []);
    }

    private function makeHelper(StubRedisCacheService $stub): ProductsHelper
    {
        $helper = (new \ReflectionClass(ProductsHelper::class))->newInstanceWithoutConstructor();
        self::accessible(new \ReflectionProperty(ProductsHelper::class, 'redisCache'))->setValue($helper, $stub);
        return $helper;
    }

    public function testGetListWarmsRouteAliasesOnCacheHit(): void
    {
        $stub = new StubRedisCacheService();
        $stub->cachedValue = [
            7 => (object) ['id' => 7, 'url' => 'product-alpha-100', 'slug_url' => 'gadgets/product-alpha-100'],
            9 => (object) ['id' => 9, 'url' => 'product-beta-200',  'slug_url' => 'gadgets/product-beta-200'],
        ];

        $result = $this->makeHelper($stub)->getList(['category_id' => 18]);

        $this->assertSame($stub->cachedValue, $result);
        $this->assertSame(
            'gadgets/product-alpha-100',
            ProductRoute::getUrlSlugAlias('product-alpha-100')
        );
        $this->assertSame(
            'gadgets/product-beta-200',
            ProductRoute::getUrlSlugAlias('product-beta-200')
        );
    }

    public function testGetListToleratesCachedRowsWithoutSlug(): void
    {
        $stub = new StubRedisCacheService();
        $stub->cachedValue = [
            7 => (object) ['id' => 7, 'url' => 'no-slug-product'],
            8 => (object) ['id' => 8, 'url' => '', 'slug_url' => ''],
        ];

        $result = $this->makeHelper($stub)->getList([]);

        $this->assertSame($stub->cachedValue, $result);
        $this->assertFalse(ProductRoute::getUrlSlugAlias('no-slug-product'));
    }

    public function testAttachImagesKeyIsTaggedPerProduct(): void
    {
        $stub = new StubRedisCacheService();
        $stub->cachedValue = null; // MISS -> parent не викликаємо, перевіряємо лише ключ

        $helper = $this->makeHelper($stub);
        $key = $stub->makeVersionedKey(
            'products_attach_images',
            self::accessible(new \ReflectionMethod(ProductsHelper::class, 'imageCacheTags'))->invoke($helper, [7, 9]),
            [[7, 9]]
        );

        $this->assertStringContainsString(CacheTags::product(7), $key);
        $this->assertStringContainsString(CacheTags::product(9), $key);
        $this->assertStringContainsString(CacheTags::PRODUCTS_ALL, $key);
    }
}
