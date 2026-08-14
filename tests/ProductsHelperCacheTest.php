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
            7 => (object) ['id' => 7, 'url' => 'delonghi-as00009450', 'slug_url' => 'coffee-machines/delonghi-as00009450'],
            9 => (object) ['id' => 9, 'url' => 'krups-ms-624571',     'slug_url' => 'coffee-machines/krups-ms-624571'],
        ];

        $result = $this->makeHelper($stub)->getList(['category_id' => 18]);

        $this->assertSame($stub->cachedValue, $result);
        $this->assertSame(
            'coffee-machines/delonghi-as00009450',
            ProductRoute::getUrlSlugAlias('delonghi-as00009450')
        );
        $this->assertSame(
            'coffee-machines/krups-ms-624571',
            ProductRoute::getUrlSlugAlias('krups-ms-624571')
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
