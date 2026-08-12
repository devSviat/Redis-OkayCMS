<?php

namespace Modules\Sviat\Redis;

use Okay\Modules\Sviat\Redis\Extenders\ImagesCacheInvalidator;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StubRedisCacheService.php';

class ImagesCacheInvalidatorTest extends TestCase
{
    public function testOnImageAddBumpsPerProductWhenProductIdInObject(): void
    {
        $stub = new StubRedisCacheService();
        $invalidator = new ImagesCacheInvalidator($stub);
        $image = (object) ['product_id' => 42];

        $invalidator->onImageAdd(123, $image);

        $this->assertSame([CacheTags::product(42)], $stub->bumps);
    }

    public function testOnImageAddBumpsGlobalWhenProductIdMissing(): void
    {
        $stub = new StubRedisCacheService();
        $invalidator = new ImagesCacheInvalidator($stub);
        $image = (object) [];

        $invalidator->onImageAdd(123, $image);

        $this->assertSame([CacheTags::PRODUCTS_ALL], $stub->bumps);
    }

    public function testOnImageUpdateBumpsPerProduct(): void
    {
        $stub = new StubRedisCacheService();
        $invalidator = new ImagesCacheInvalidator($stub);

        $invalidator->onImageUpdate(true, 5, ['product_id' => 7]);

        $this->assertSame([CacheTags::product(7)], $stub->bumps);
    }

    public function testOnImageDeleteBumpsGlobalSinceProductIdUnrecoverable(): void
    {
        $stub = new StubRedisCacheService();
        $invalidator = new ImagesCacheInvalidator($stub);

        $invalidator->onImageDelete(true, [3]);

        $this->assertSame([CacheTags::PRODUCTS_ALL], $stub->bumps);
    }
}
