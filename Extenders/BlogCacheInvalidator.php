<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

/**
 * Інвалідує кеш блогу і авторів при їх зміні.
 */
class BlogCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    /** Виконується після оновлення запису блога. */
    public function onBlogUpdate($output, $ids, $object): void
    {
        if (!$output) {
            return;
        }

        $this->redis->invalidateBlogCaches();
    }

    /** Виконується після додавання запису блога. */
    public function onBlogAdd($output, $object): void
    {
        $id = (int)$output;
        if ($id > 0) {
            $this->redis->invalidateBlogCaches();
        }
    }

    /** Triggered after `BlogEntity::delete`. */
    public function onBlogDelete($output, $ids): void
    {
        if (!$output) {
            return;
        }

        $this->redis->invalidateBlogCaches();
    }
}
