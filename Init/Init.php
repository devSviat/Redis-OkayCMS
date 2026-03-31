<?php

namespace Okay\Modules\Sviat\Redis\Init;

use Okay\Core\Modules\AbstractInit;
use Okay\Entities\BlogEntity;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Sviat\Redis\Extenders\BlogRelatedCacheExtender;
use Okay\Modules\Sviat\Redis\Extenders\VariantsCacheExtender;

class Init extends AbstractInit
{
    public function install()
    {
        $this->setBackendMainController('RedisSettingsAdmin');
    }

    public function init()
    {
        $this->registerBackendController('RedisSettingsAdmin');
        $this->addBackendControllerPermission('RedisSettingsAdmin', 'settings');

        // Cache related blog products.
        $this->registerChainExtension(
            ['class' => BlogEntity::class, 'method' => 'getRelatedProducts'],
            ['class' => BlogRelatedCacheExtender::class, 'method' => 'getRelatedProducts']
        );

        // Invalidate variants cache on any variants change.
        $this->registerQueueExtension(
            ['class' => VariantsEntity::class, 'method' => 'update'],
            ['class' => VariantsCacheExtender::class, 'method' => 'onVariantsUpdate']
        );
        $this->registerQueueExtension(
            ['class' => VariantsEntity::class, 'method' => 'add'],
            ['class' => VariantsCacheExtender::class, 'method' => 'onVariantsAdd']
        );
        $this->registerQueueExtension(
            ['class' => VariantsEntity::class, 'method' => 'delete'],
            ['class' => VariantsCacheExtender::class, 'method' => 'onVariantsDelete']
        );
    }
}

