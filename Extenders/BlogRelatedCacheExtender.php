<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class BlogRelatedCacheExtender implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    /** Cache wrapper for `BlogEntity::getRelatedProducts`. */
    public function getRelatedProducts($output, array $filter = [])
    {
        if (!$this->redis->canCache('blog_entity_related_products')) {
            return $output;
        }

        $key = $this->redis->makeKey('blog_entity_related_products', [$filter]);
        $cached = $this->redis->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        // `$output` is already computed by core; store and return it.
        $ttl = $this->redis->getHelperTtl('blog_entity_related_products') ?? 600;
        $this->redis->set($key, $output, $ttl);
        return $output;
    }
}

