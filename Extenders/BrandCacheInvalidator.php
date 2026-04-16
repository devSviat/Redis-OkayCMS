<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

/**
 * Інвалідує кеш брендів при їх редагуванні або видаленні.
 */
class BrandCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    /** Виконується після оновлення бренду. */
    public function onBrandUpdate($output, $ids, $object): void
    {
        if (!$output) {
            return;
        }

        $this->redis->invalidateBrandCaches();
    }

    /** Виконується після додавання бренду. */
    public function onBrandAdd($output, $object): void
    {
        $id = (int)$output;
        if ($id > 0) {
            // Новий бренд може повпливати на листинги
            $this->redis->invalidateBrandCaches();
        }
    }

    /** Виконується після видалення бренду. */
    public function onBrandDelete($output, $ids): void
    {
        if (!$output) {
            return;
        }

        $this->redis->invalidateBrandCaches();
    }
}
