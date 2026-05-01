<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
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
        if (!$this->redis->isEnabled()) {
            return $output;
        }

        $key = $this->redis->makeVersionedKey(
            'blog_entity_related_products',
            [CacheTags::AUTHORS_BLOG, CacheTags::PRODUCTS_ALL],
            [$filter]
        );
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
