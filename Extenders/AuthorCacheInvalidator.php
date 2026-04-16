<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

/**
 * Інвалідує кеш авторів при їх редагуванні або видаленні.
 */
class AuthorCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    /** Triggered after `AuthorsEntity::update`. */
    public function onAuthorUpdate($output, $ids, $object): void
    {
        if (!$output) {
            return;
        }

        $this->redis->invalidateBlogCaches();
    }

    /** Triggered after `AuthorsEntity::add`. */
    public function onAuthorAdd($output, $object): void
    {
        $id = (int)$output;
        if ($id > 0) {
            $this->redis->invalidateBlogCaches();
        }
    }

    /** Triggered after `AuthorsEntity::delete`. */
    public function onAuthorDelete($output, $ids): void
    {
        if (!$output) {
            return;
        }

        $this->redis->invalidateBlogCaches();
    }
}
