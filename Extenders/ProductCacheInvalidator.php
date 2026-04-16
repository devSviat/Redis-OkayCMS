<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

/**
 * Інвалідує кеш продуктів при їх редагуванні або видаленні.
 */
class ProductCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    /** Виконується після оновлення товару. */
    public function onProductUpdate($output, $ids, $object): void
    {
        if (!$output) {
            return;
        }

        // Очистити весь кеш товарів при будь-якій зміні
        $this->redis->invalidateProductCaches();
    }

    /** Виконується після додавання товару. */
    public function onProductAdd($output, $object): void
    {
        $id = (int)$output;
        if ($id > 0) {
            // Новий товар може повпливати на листинги
            $this->redis->invalidateProductCaches();
        }
    }

    /** Виконується після видалення товару. */
    public function onProductDelete($output, $ids): void
    {
        if (!$output) {
            return;
        }
        // Удаленный товар требует полной инвалидации
        $this->redis->invalidateProductCaches();
    }
}
