<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class BlogHelper extends \Okay\Helpers\BlogHelper
{
    private RedisCacheService $redis;

    public function __construct(
        EntityFactory $entityFactory,
        \Okay\Helpers\AuthorsHelper $authorsHelper,
        RedisCacheService $redis
    ) {
        parent::__construct($entityFactory, $authorsHelper);
        $this->redis = $redis;
    }

    public function getList($filter = [], $sortName = null, $excludedFields = null)
    {
        if (!$this->redis->isEnabled()) {
            return parent::getList($filter, $sortName, $excludedFields);
        }
        $key = $this->redis->makeVersionedKey('blog_get_list', [CacheTags::AUTHORS_BLOG], [$filter, $sortName, $excludedFields]);
        $cached = $this->redis->get($key);
        if (is_array($cached)) {
            return ExtenderFacade::execute(
                \Okay\Helpers\BlogHelper::class . '::getList',
                $cached,
                func_get_args()
            );
        }
        $result = parent::getList($filter, $sortName, $excludedFields);
        $this->redis->set($key, $result, $this->redis->getHelperTtl('blog_get_list'));
        return $result;
    }

    public function attachPostData($post)
    {
        if (empty($post->id) || !$this->redis->isEnabled()) {
            return parent::attachPostData($post);
        }
        $key = $this->redis->makeVersionedKey('blog_attach_post_data', [CacheTags::AUTHORS_BLOG], [(int) $post->id]);
        $cached = $this->redis->get($key);
        if (is_object($cached) || is_array($cached)) {
            return ExtenderFacade::execute(
                \Okay\Helpers\BlogHelper::class . '::attachPostData',
                $cached,
                func_get_args()
            );
        }
        $result = parent::attachPostData($post);
        $this->redis->set($key, $result, $this->redis->getHelperTtl('blog_attach_post_data') ?? 3600);
        return $result;
    }
}
