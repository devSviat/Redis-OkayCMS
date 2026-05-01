<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class BlogCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    public function onBlogUpdate($output, $ids, $object): void
    {
        if ($output) { $this->redis->bump(CacheTags::AUTHORS_BLOG); }
    }

    public function onBlogAdd($output, $object): void
    {
        if ((int) $output > 0) { $this->redis->bump(CacheTags::AUTHORS_BLOG); }
    }

    public function onBlogDelete($output, $ids): void
    {
        if ($output) { $this->redis->bump(CacheTags::AUTHORS_BLOG); }
    }
}
