<?php

namespace Modules\Sviat\Redis;

use Okay\Core\EntityFactory;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Sviat\Redis\Extenders\VariantsCacheInvalidator;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StubRedisCacheService.php';

/**
 * Ключ products_get_list складається з [PRODUCTS_LIST, PRODUCTS_ALL], тож
 * bump лише pver:<id> для нього — no-op. Через це після StockSync лістинг
 * до 300 с показував стару ціну й наявність.
 */
class VariantsCacheInvalidatorTest extends TestCase
{
    private function makeInvalidator(StubRedisCacheService $stub, array $variants): VariantsCacheInvalidator
    {
        $variantsEntity = $this->createStub(VariantsEntity::class);
        $variantsEntity->method('noLimit')->willReturnSelf();
        $variantsEntity->method('find')->willReturn($variants);

        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($variantsEntity);

        return new VariantsCacheInvalidator($entityFactory, $stub);
    }

    public function testPriceChangeBumpsProductsList(): void
    {
        $stub = new StubRedisCacheService();
        $invalidator = $this->makeInvalidator($stub, [(object) ['id' => 5, 'product_id' => 42]]);

        $invalidator->onVariantsUpdate(true, [5], (object) ['price' => 199]);

        $this->assertContains(CacheTags::product(42), $stub->bumps);
        $this->assertContains(CacheTags::PRODUCTS_LIST, $stub->bumps);
    }

    public function testStockChangeBumpsProductsList(): void
    {
        $stub = new StubRedisCacheService();
        $invalidator = $this->makeInvalidator($stub, [(object) ['id' => 5, 'product_id' => 42]]);

        $invalidator->onVariantsUpdate(true, [5], (object) ['stock' => 0]);

        $this->assertContains(CacheTags::PRODUCTS_LIST, $stub->bumps);
    }

    /**
     * StockSync оновлює варіанти чанками по 500. Кожен чанк не має давати
     * власний INCR — інакше імпорт обнуляє лістинговий кеш тисячі разів
     * поспіль з тим самим результатом, що й один раз.
     */
    public function testRepeatedUpdatesBumpProductsListOnlyOncePerProcess(): void
    {
        $stub = new StubRedisCacheService();
        $stub->collapseBumpOnce = true;
        $invalidator = $this->makeInvalidator($stub, [(object) ['id' => 5, 'product_id' => 42]]);

        $invalidator->onVariantsUpdate(true, [5], (object) ['stock' => 1]);
        $invalidator->onVariantsUpdate(true, [5], (object) ['stock' => 2]);
        $invalidator->onVariantsUpdate(true, [5], (object) ['stock' => 3]);

        $this->assertSame(
            1,
            count(array_filter($stub->bumps, fn($t) => $t === CacheTags::PRODUCTS_LIST))
        );
    }

    public function testAddAlwaysBumpsProductsList(): void
    {
        $stub = new StubRedisCacheService();
        $invalidator = $this->makeInvalidator($stub, [(object) ['id' => 5, 'product_id' => 42]]);

        $invalidator->onVariantsAdd(5, (object) ['product_id' => 42]);

        $this->assertContains(CacheTags::PRODUCTS_LIST, $stub->bumps);
    }
}
