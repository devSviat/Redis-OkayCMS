<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Entity\RelatedProductsInterface;
use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Redis\Helpers\AuthorsHelper;
use Okay\Modules\Sviat\Redis\Helpers\BlogHelper;
use Okay\Modules\Sviat\Redis\Helpers\BrandsHelper;
use Okay\Modules\Sviat\Redis\Helpers\RelatedProductsHelper;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StubRedisCacheService.php';
require_once __DIR__ . '/PrivateAccess.php';

/**
 * Помилка в ключі не ламає сторінку: два різні запити просто віддають одне й те
 * саме, а застарілий тег лишає дані старими. Тому перевіряється ключ, а не HTML.
 */
class HelpersCacheTest extends TestCase
{
    use PrivateAccess;

    private function build(string $class, StubRedisCacheService $redis, array $parentProps = []): object
    {
        $helper = new \ReflectionClass($class)->newInstanceWithoutConstructor();
        self::accessible(new \ReflectionProperty($class, 'redis'))->setValue($helper, $redis);
        foreach ($parentProps as $name => [$owner, $value]) {
            self::accessible(new \ReflectionProperty($owner, $name))->setValue($helper, $value);
        }

        return $helper;
    }

    private function factoryReturning(array $rows): EntityFactory
    {
        $entity = new class ($rows) {
            public function __construct(private array $rows) {}
            public function __call($name, $args) { return $name === 'find' ? $this->rows : $this; }
        };
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        return $factory;
    }

    /** @return list<array{class-string, string, string, list<string>}> */
    private function listHelpers(): array
    {
        return [
            [BrandsHelper::class, 'getList', 'brands_get_list', [CacheTags::BRANDS]],
            [AuthorsHelper::class, 'getList', 'authors_get_list', [CacheTags::AUTHORS_BLOG]],
            [BlogHelper::class, 'getList', 'blog_get_list', [CacheTags::AUTHORS_BLOG]],
        ];
    }

    public function testListHelpersServeHitWithRightKeyAndTags(): void
    {
        foreach ($this->listHelpers() as [$class, $method, $name, $tags]) {
            $redis = new StubRedisCacheService();
            $redis->cachedValue = [3 => (object) ['id' => 3]];

            $result = $this->build($class, $redis)->$method(['visible' => 1], 'position', []);

            self::assertSame($redis->cachedValue, $result, $class);
            self::assertStringStartsWith($name . '|' . implode(',', $tags) . '|', (string) $redis->lastKey, $class);
        }
    }

    public function testListHelpersSeparateKeysByFilterSortAndFields(): void
    {
        foreach ($this->listHelpers() as [$class, $method]) {
            $keys = [];
            foreach ([[['a' => 1], 's', []], [['a' => 2], 's', []], [['a' => 1], 'z', []], [['a' => 1], 's', ['x']]] as $args) {
                $redis = new StubRedisCacheService();
                $redis->cachedValue = [];
                $this->build($class, $redis)->$method(...$args);
                $keys[] = $redis->lastKey;
            }

            self::assertCount(4, array_unique($keys), $class);
        }
    }

    public function testAuthorsMissStoresResultWithConfiguredTtl(): void
    {
        $redis = new StubRedisCacheService();
        $redis->helperTtls['authors_get_list'] = 120;
        $rows = [3 => (object) ['id' => 3]];
        $helper = $this->build(AuthorsHelper::class, $redis, [
            'entityFactory' => [\Okay\Helpers\AuthorsHelper::class, $this->factoryReturning($rows)],
        ]);

        self::assertSame($rows, $helper->getList([], null, []));

        self::assertCount(1, $redis->stored);
        self::assertSame([$rows, 120], array_values($redis->stored)[0]);
    }

    public function testDisabledCacheNeverTouchesRedis(): void
    {
        $redis = new StubRedisCacheService();
        $redis->enabled = false;
        $rows = [3 => (object) ['id' => 3]];
        $helper = $this->build(AuthorsHelper::class, $redis, [
            'entityFactory' => [\Okay\Helpers\AuthorsHelper::class, $this->factoryReturning($rows)],
        ]);

        self::assertSame($rows, $helper->getList([], null, []));
        self::assertNull($redis->lastKey);
        self::assertSame([], $redis->stored);
    }

    public function testAttachPostDataHitIsKeyedByPostId(): void
    {
        $keys = [];
        foreach ([1, 2] as $id) {
            $redis = new StubRedisCacheService();
            $redis->cachedValue = (object) ['id' => $id, 'author' => 'закешований'];

            $result = $this->build(BlogHelper::class, $redis)->attachPostData((object) ['id' => $id]);

            self::assertSame($redis->cachedValue, $result);
            self::assertStringStartsWith('blog_attach_post_data|' . CacheTags::AUTHORS_BLOG . '|', (string) $redis->lastKey);
            $keys[] = $redis->lastKey;
        }

        self::assertNotSame($keys[0], $keys[1]);
    }

    public function testAttachPostDataWithoutIdSkipsCache(): void
    {
        $redis = new StubRedisCacheService();

        self::assertFalse($this->build(BlogHelper::class, $redis)->attachPostData((object) []));
        self::assertNull($redis->lastKey);
    }

    public function testRelatedProductsKeyDependsOnEntityAndFilter(): void
    {
        $first = new class implements RelatedProductsInterface {
            public function getRelatedProducts(array $filter = []) { return []; }
            public function addRelatedProduct($objectId, $relatedId, $position = 0) {}
            public function deleteRelatedProduct($objectId, $relatedId = null) {}
        };
        $second = new class implements RelatedProductsInterface {
            public function getRelatedProducts(array $filter = []) { return []; }
            public function addRelatedProduct($objectId, $relatedId, $position = 0) {}
            public function deleteRelatedProduct($objectId, $relatedId = null) {}
        };

        $keys = [];
        foreach ([[$first, ['p' => 1]], [$first, ['p' => 2]], [$second, ['p' => 1]]] as [$entity, $filter]) {
            $redis = new StubRedisCacheService();
            $redis->cachedValue = [];
            $this->build(RelatedProductsHelper::class, $redis)->getRelatedProductsList($entity, $filter);
            self::assertStringStartsWith(
                'related_products_list|' . CacheTags::PRODUCTS_ALL . ',' . CacheTags::PRODUCTS_LIST . '|',
                (string) $redis->lastKey
            );
            $keys[] = $redis->lastKey;
        }

        self::assertCount(3, array_unique($keys));
    }
}
