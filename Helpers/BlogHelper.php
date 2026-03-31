<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\EntityFactory;
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
        if (!$this->redis->canCache('blog_get_list')) {
            return parent::getList($filter, $sortName, $excludedFields);
        }

        $key = $this->redis->makeKey('blog_get_list', [$filter, $sortName, $excludedFields]);
        $cached = $this->redis->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = parent::getList($filter, $sortName, $excludedFields);
        $this->redis->set($key, $result, $this->redis->getHelperTtl('blog_get_list'));
        return $result;
    }

    public function attachPostData($post)
    {
        if (empty($post->id)) {
            return parent::attachPostData($post);
        }

        if (!$this->redis->canCache('blog_attach_post_data')) {
            return parent::attachPostData($post);
        }

        $key = $this->redis->makeKey('blog_attach_post_data', [(int) $post->id]);
        $cached = $this->redis->get($key);
        if (is_object($cached) || is_array($cached)) {
            return $cached;
        }

        $result = parent::attachPostData($post);
        $this->redis->set($key, $result, $this->redis->getHelperTtl('blog_attach_post_data') ?? 3600);
        return $result;
    }
}

