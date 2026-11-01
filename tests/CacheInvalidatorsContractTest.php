<?php

namespace Modules\Sviat\Redis;

use Okay\Modules\Sviat\Redis\Extenders\AuthorCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\BlogCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\BrandCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\CategoryCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\CurrencyCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\FeaturesCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\FeaturesValuesCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\ProductCacheInvalidator;
use Okay\Modules\Sviat\Redis\Extenders\SpecialImagesCacheInvalidator;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StubRedisCacheService.php';

/**
 * Пропущений тег не видно ніде, крім вітрини: там лишається стара ціна чи назва
 * до кінця TTL. Тому фіксується точний набір тегів кожної події.
 */
class CacheInvalidatorsContractTest extends TestCase
{
    private const array ALL_AND_LIST = [CacheTags::PRODUCTS_ALL, CacheTags::PRODUCTS_LIST];

    /** @return array<string, array{class-string, string, string, list<string>}> */
    public static function events(): array
    {
        $rows = [
            [CategoryCacheInvalidator::class, 'onCategory', [CacheTags::CATEGORIES, CacheTags::PRODUCTS_LIST]],
            [BrandCacheInvalidator::class, 'onBrand', [CacheTags::BRANDS, CacheTags::PRODUCTS_LIST]],
            [BlogCacheInvalidator::class, 'onBlog', [CacheTags::AUTHORS_BLOG]],
            [AuthorCacheInvalidator::class, 'onAuthor', [CacheTags::AUTHORS_BLOG]],
            [CurrencyCacheInvalidator::class, 'onCurrency', [CacheTags::PRODUCTS_ALL]],
            [FeaturesCacheInvalidator::class, 'onFeature', self::ALL_AND_LIST],
            [FeaturesValuesCacheInvalidator::class, 'onFeatureValue', self::ALL_AND_LIST],
            [SpecialImagesCacheInvalidator::class, 'onSpecialImage', [CacheTags::PRODUCTS_ALL]],
        ];

        $cases = [];
        foreach ($rows as [$class, $prefix, $tags]) {
            foreach (['Add', 'Update', 'Delete'] as $event) {
                $cases[$prefix . $event] = [$class, $prefix . $event, $event, $tags];
            }
        }

        return $cases;
    }

    /** @param list<string> $expected */
    #[DataProvider('events')]
    public function testSuccessfulEventBumpsExactTags(string $class, string $method, string $event, array $expected): void
    {
        $stub = new StubRedisCacheService();

        new $class($stub)->$method(...self::successArgs($event));

        self::assertSame($expected, $stub->bumps);
    }

    /** @param list<string> $tags */
    #[DataProvider('events')]
    public function testFailedEventBumpsNothing(string $class, string $method, string $event, array $tags): void
    {
        $stub = new StubRedisCacheService();

        new $class($stub)->$method(...self::failureArgs($event));

        self::assertSame([], $stub->bumps, 'запис не відбувся — кеш лишається дійсним');
    }

    public function testProductUpdateBumpsEachProductAndList(): void
    {
        $stub = new StubRedisCacheService();

        new ProductCacheInvalidator($stub)->onProductUpdate(true, [7, '9'], (object) []);

        self::assertSame([CacheTags::product(7), CacheTags::product(9), CacheTags::PRODUCTS_LIST], $stub->bumps);
    }

    public function testProductUpdateAcceptsSingleIdAndIgnoresInvalid(): void
    {
        $stub = new StubRedisCacheService();

        new ProductCacheInvalidator($stub)->onProductUpdate(true, 7, (object) []);
        self::assertSame([CacheTags::product(7), CacheTags::PRODUCTS_LIST], $stub->bumps);

        $stub = new StubRedisCacheService();
        new ProductCacheInvalidator($stub)->onProductUpdate(true, [0, -1], (object) []);
        self::assertSame([CacheTags::PRODUCTS_LIST], $stub->bumps);
    }

    public function testProductAddTouchesOnlyLists(): void
    {
        $stub = new StubRedisCacheService();

        new ProductCacheInvalidator($stub)->onProductAdd(5, (object) []);

        self::assertSame([CacheTags::PRODUCTS_LIST], $stub->bumps);
    }

    /** Після видалення id товарів уже невідомі кешу, тож скидається все. */
    public function testProductDeleteBumpsAllAndList(): void
    {
        $stub = new StubRedisCacheService();

        new ProductCacheInvalidator($stub)->onProductDelete(true, [7]);

        self::assertSame(self::ALL_AND_LIST, $stub->bumps);
    }

    public function testFailedProductEventsBumpNothing(): void
    {
        $stub = new StubRedisCacheService();
        $invalidator = new ProductCacheInvalidator($stub);

        $invalidator->onProductUpdate(false, [7], (object) []);
        $invalidator->onProductAdd(0, (object) []);
        $invalidator->onProductDelete(false, [7]);

        self::assertSame([], $stub->bumps);
    }

    private static function successArgs(string $event): array
    {
        return $event === 'Add' ? [5, (object) []] : ($event === 'Update' ? [true, [1], (object) []] : [true, [1]]);
    }

    private static function failureArgs(string $event): array
    {
        return $event === 'Add' ? [0, (object) []] : ($event === 'Update' ? [false, [1], (object) []] : [false, [1]]);
    }
}
