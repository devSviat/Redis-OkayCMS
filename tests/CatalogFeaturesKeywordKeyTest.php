<?php

namespace Modules\Sviat\Redis;

use Okay\Helpers\CatalogHelper as CoreCatalogHelper;
use Okay\Helpers\CategoriesHelper as CoreCategoriesHelper;
use Okay\Helpers\FilterHelper;
use Okay\Modules\Sviat\Redis\Helpers\CatalogHelper;
use Okay\Modules\Sviat\Redis\Helpers\CategoriesHelper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/StubRedisCacheService.php';
require_once __DIR__ . '/PrivateAccess.php';

/**
 * Фильтр каталога свойств зависит от keyword запроса, а ключ кеша — нет.
 * Первый поисковый запрос после протухания записывал суженный фильтр в общий
 * ключ и отдавал его всему каталогу до конца TTL: 17 вариантов вместо 245.
 */
class CatalogFeaturesKeywordKeyTest extends TestCase
{
    use PrivateAccess;

    private const CACHED = ['sentinel' => 'из кеша'];

    private function filterHelper(?string $keyword): FilterHelper
    {
        return new class ($keyword) extends FilterHelper {
            /** @var string|null */
            private $keyword;

            public function __construct(?string $keyword)
            {
                $this->keyword = $keyword;
            }

            public function getKeyword(): ?string
            {
                return $this->keyword;
            }
        };
    }

    /** Сущность, возвращающая пустую выборку: запрос нас тут не интересует. */
    private function featuresEntity(): object
    {
        return new class {
            public function mappedBy(string $field): self
            {
                return $this;
            }

            public function find(array $filter = []): array
            {
                return [];
            }
        };
    }

    private function catalogHelper(?string $keyword, StubRedisCacheService $cache): CatalogHelper
    {
        $reflection = new ReflectionClass(CatalogHelper::class);
        $helper = $reflection->newInstanceWithoutConstructor();

        self::accessible($reflection->getProperty('redisCache'))->setValue($helper, $cache);
        self::accessible($reflection->getProperty('filterHelper'))->setValue($helper, $this->filterHelper($keyword));

        $core = new ReflectionClass(CoreCatalogHelper::class);
        self::accessible($core->getProperty('filterHelper'))->setValue($helper, $this->filterHelper($keyword));
        self::accessible($core->getProperty('featuresEntity'))->setValue($helper, $this->featuresEntity());

        return $helper;
    }

    private function categoriesHelper(?string $keyword, StubRedisCacheService $cache): CategoriesHelper
    {
        $reflection = new ReflectionClass(CategoriesHelper::class);
        $helper = $reflection->newInstanceWithoutConstructor();

        self::accessible($reflection->getProperty('redisCache'))->setValue($helper, $cache);

        $core = new ReflectionClass(CoreCategoriesHelper::class);
        self::accessible($core->getProperty('filterHelper'))->setValue($helper, $this->filterHelper($keyword));
        self::accessible($core->getProperty('catalogHelper'))->setValue($helper, $this->catalogHelper($keyword, new StubRedisCacheService()));

        return $helper;
    }

    private function cache(): StubRedisCacheService
    {
        $cache = new StubRedisCacheService();
        $cache->cachedValue = self::CACHED;
        return $cache;
    }

    public function testCatalogFeaturesFilterIgnoresCacheOnSearch(): void
    {
        $cache = $this->cache();

        $filter = $this->catalogHelper('дрель', $cache)->getCatalogFeaturesFilter();

        $this->assertSame('дрель', $filter['product_keyword']);
        $this->assertSame([], $cache->stored, 'поисковый фильтр не должен попасть в общий ключ');
    }

    public function testCatalogFeaturesFilterUsesCacheWithoutSearch(): void
    {
        $cache = $this->cache();

        $this->assertSame(self::CACHED, $this->catalogHelper(null, $cache)->getCatalogFeaturesFilter());
    }

    public function testCatalogFeaturesIgnoresCacheOnSearchWhenFilterIsResolvedInside(): void
    {
        $cache = $this->cache();

        $this->assertSame([], $this->catalogHelper('дрель', $cache)->getCatalogFeatures());
        $this->assertSame([], $cache->stored);
    }

    /** Явный фильтр несёт keyword сам, поэтому ключ корректен и кеш работает. */
    public function testCatalogFeaturesKeepsCacheForExplicitFilter(): void
    {
        $cache = $this->cache();

        $result = $this->catalogHelper('дрель', $cache)
            ->getCatalogFeatures(['in_filter' => 1, 'product_keyword' => 'дрель']);

        $this->assertSame(self::CACHED, $result);
    }

    public function testCategoriesCatalogFeaturesIgnoresCacheOnSearch(): void
    {
        $cache = $this->cache();

        $result = $this->categoriesHelper('дрель', $cache)->getCatalogFeatures((object) ['id' => 7]);

        $this->assertSame([], $result);
        $this->assertSame([], $cache->stored);
    }

    public function testCategoriesCatalogFeaturesUsesCacheWithoutSearch(): void
    {
        $cache = $this->cache();

        $result = $this->categoriesHelper(null, $cache)->getCatalogFeatures((object) ['id' => 7]);

        $this->assertSame(self::CACHED, $result);
    }
}
