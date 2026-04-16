<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

/**
 * Інвалідує кеш категорій при їх редагуванні або видаленні.
 */
class CategoryCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    /** Виконується після оновлення категорії. */
    public function onCategoryUpdate($output, $ids, $object): void
    {
        if (!$output) {
            return;
        }

        // Очистити кеш категорій та продуктів
        $this->redis->invalidateCategoryCaches();
    }

    /** Виконується після додавання категорії. */
    public function onCategoryAdd($output, $object): void
    {
        $id = (int)$output;
        if ($id > 0) {
            // Нова категорія може впливати на листинги
            $this->redis->invalidateCategoryCaches();
        }
    }

    /** Виконується після видалення категорії. */
    public function onCategoryDelete($output, $ids): void
    {
        if (!$output) {
            return;
        }

        $this->redis->invalidateCategoryCaches();
    }
}
