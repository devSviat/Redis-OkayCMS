<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class FeaturesValuesCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    public function onFeatureValueAdd($output, $object): void
    {
        if ((int) $output > 0) { $this->bumpAll(); }
    }

    public function onFeatureValueUpdate($output, $ids, $object): void
    {
        if ($output) { $this->bumpAll(); }
    }

    public function onFeatureValueDelete($output, $ids): void
    {
        if ($output) { $this->bumpAll(); }
    }

    private function bumpAll(): void
    {
        $this->redis->bump(CacheTags::PRODUCTS_ALL);
        $this->redis->bump(CacheTags::PRODUCTS_LIST);
    }
}
