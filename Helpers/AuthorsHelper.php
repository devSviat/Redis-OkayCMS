<?php

namespace Okay\Modules\Sviat\Redis\Helpers;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class AuthorsHelper extends \Okay\Helpers\AuthorsHelper
{
    private RedisCacheService $redis;

    public function __construct(EntityFactory $entityFactory, RedisCacheService $redis)
    {
        parent::__construct($entityFactory);
        $this->redis = $redis;
    }

    public function getList($filter = [], $sortName = null, $excludedFields = null)
    {
        if (!$this->redis->canCache('authors_get_list')) {
            return parent::getList($filter, $sortName, $excludedFields);
        }

        $key = $this->redis->makeKey('authors_get_list', [$filter, $sortName, $excludedFields]);
        $cached = $this->redis->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = parent::getList($filter, $sortName, $excludedFields);
        $this->redis->set($key, $result, $this->redis->getHelperTtl('authors_get_list'));
        return $result;
    }
}

